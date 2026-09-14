"""
Instagram DM microservice — logs in to Instagram via instagrapi (an
unofficial private-API client, not the official Graph API) and bridges DMs to
and from the Laravel app, for as many connected accounts as there are
`instagrapi` channels.

Multi-tenant by design: this process holds NO Instagram credentials of its
own (nothing hardcoded, nothing read from the environment). Every account it
knows about was handed to it by Laravel, one at a time, via POST /connect —
each agent owner's own username/password, entered on their own Channels tab
and kept in the `channels.credentials` column there. This service only ever
holds what it needs to keep a session alive: an instagrapi Client per
channel_uuid, in `sessions`, persisted to instagram_sessions/{channel_uuid}.json.

Laravel calls IN to this service:
  - POST /connect            — {channel_uuid, username, password}. Logs in,
                                saves the session. Called by
                                InstagrapiDriver::connect() when a channel is
                                saved from the Channels tab.
  - POST /disconnect         — {channel_uuid}. Logs out and forgets the
                                session. Called by InstagrapiDriver::disconnect().
  - GET  /status/{channel_uuid} — one account's login state.
  - POST /send               — {channel_uuid, user_id, text}. Send one DM.
  - GET  /health              — process-wide liveness, used by
                                `php artisan instagram:status`.

This service calls OUT to Laravel:
  - A background task polls every connected session's direct_threads() every
    POLL_INTERVAL_SECONDS and POSTs each new customer message — tagged with
    that session's channel_uuid — to POST /internal/instagram/incoming
    (LARAVEL_URL, below). instagrapi has no push/webhook mechanism, so
    polling is the only option.
"""

from __future__ import annotations

import asyncio
import logging
import os
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from typing import Optional

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI
from pydantic import BaseModel

from instagrapi import Client
from instagrapi.exceptions import BadPassword, ChallengeRequired, LoginRequired, TwoFactorRequired

load_dotenv()

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("instagram_service")

SESSIONS_DIR = "instagram_sessions"
POLL_INTERVAL_SECONDS = 10

# Where this process posts newly-seen DMs. Matches the port `php artisan
# serve` binds by default; override via LARAVEL_URL if that differs locally.
LARAVEL_URL = os.environ.get("LARAVEL_URL", "http://127.0.0.1:8000").rstrip("/")
INCOMING_ENDPOINT = f"{LARAVEL_URL}/internal/instagram/incoming"


@dataclass
class AccountSession:
    """One connected channel's login state and instagrapi client. Each
    channel gets its own Client — instagrapi's client carries per-account
    cookies/device state, so accounts cannot share one."""

    client: Client
    username: Optional[str] = None
    logged_in: bool = False
    challenged: bool = False
    error: Optional[str] = None
    # In-memory dedup only, per account — this service is not the source of
    # truth for message history (Laravel's `messages.external_message_id`
    # unique constraint is); this just avoids re-forwarding the same DM every
    # 10-second poll. Resets on restart, which is fine.
    seen_message_ids: set = field(default_factory=set)


# channel_uuid -> AccountSession. Everything this process knows lives here;
# there is deliberately no fallback single-account global state.
sessions: dict[str, AccountSession] = {}

_poll_task: Optional[asyncio.Task] = None


def _session_file(channel_uuid: str) -> str:
    return os.path.join(SESSIONS_DIR, f"{channel_uuid}.json")


def _error_type(session: AccountSession) -> Optional[str]:
    """Collapses instagrapi's many failure modes to the three-value contract
    InstagrapiDriver::connect() reads: null, "bad_credentials", or
    "challenge_required"."""
    if session.logged_in:
        return None

    return "challenge_required" if session.challenged else "bad_credentials"


def _login(channel_uuid: str, username: str, password: str) -> AccountSession:
    """Logs in for one channel, creating its session if this is the first
    time. Reuses a saved session file when there is one, so a re-connect
    (e.g. after this process restarts) does not risk a fresh challenge every
    time. Never raises — every failure mode instagrapi can throw is captured
    on the returned session instead, since both callers (POST /connect and
    startup) need to keep running either way."""
    session = sessions.get(channel_uuid) or AccountSession(client=Client())
    session.username = username

    session_file = _session_file(channel_uuid)

    try:
        if os.path.exists(session_file):
            session.client.load_settings(session_file)

        session.client.login(username, password)

        os.makedirs(SESSIONS_DIR, exist_ok=True)
        session.client.dump_settings(session_file)

        session.logged_in = True
        session.challenged = False
        session.error = None
        logger.info("[%s] Logged in to Instagram as %s", channel_uuid, username)
    except (ChallengeRequired, TwoFactorRequired) as exc:
        session.logged_in = False
        session.challenged = True
        session.error = str(exc)
        logger.error("[%s] Instagram login challenged for %s: %s", channel_uuid, username, exc)
    except BadPassword as exc:
        session.logged_in = False
        session.challenged = False
        session.error = str(exc)
        logger.error("[%s] Bad credentials for %s: %s", channel_uuid, username, exc)
    except Exception as exc:  # noqa: BLE001 - any other instagrapi/network failure must not crash the service
        session.logged_in = False
        session.challenged = False
        session.error = str(exc)
        logger.error("[%s] Instagram login failed for %s: %s", channel_uuid, username, exc)

    sessions[channel_uuid] = session

    return session


async def _poll_session(channel_uuid: str, session: AccountSession) -> int:
    """One pass over one account's DM threads. Returns how many new messages
    were forwarded to Laravel."""
    if not session.logged_in:
        return 0

    try:
        threads = session.client.direct_threads(amount=20)
    except LoginRequired:
        session.logged_in = False
        session.challenged = True
        logger.error("[%s] Instagram session expired mid-poll; needs re-login.", channel_uuid)
        return 0
    except Exception as exc:  # noqa: BLE001
        logger.error("[%s] direct_threads() failed: %s", channel_uuid, exc)
        return 0

    forwarded = 0

    async with httpx.AsyncClient(timeout=10) as client:
        for thread in threads:
            # Only threads with something unread are worth reading messages out of.
            if getattr(thread, "read_state", 1) != 0:
                continue

            for message in thread.messages:
                message_id = str(message.id)

                if message_id in session.seen_message_ids:
                    continue

                session.seen_message_ids.add(message_id)

                # direct_threads() returns both directions — skip our own
                # outgoing messages, which are not something to reply to.
                if message.user_id == session.client.user_id:
                    continue

                text = (message.text or "").strip()

                if not text:
                    continue  # A media-only DM (photo/reel share) — not forwarded yet.

                try:
                    username = session.client.user_info(message.user_id).username
                except Exception:  # noqa: BLE001
                    username = None

                payload = {
                    "channel_uuid": channel_uuid,
                    "from_user_id": str(message.user_id),
                    "from_username": username,
                    "text": text,
                    "message_id": message_id,
                }

                try:
                    response = await client.post(INCOMING_ENDPOINT, json=payload)
                    response.raise_for_status()
                    forwarded += 1
                except Exception as exc:  # noqa: BLE001
                    logger.error("[%s] Failed to forward message %s to Laravel: %s", channel_uuid, message_id, exc)
                    # Deliberately left in seen_message_ids: retrying a failed
                    # forward forever risks a flood once Laravel comes back.
                    # The message stays unread on Instagram's side regardless,
                    # so `POST /webhook/receive` can be called by hand later.

    return forwarded


async def _poll_all() -> int:
    """One pass across every connected account, not just one."""
    total = 0

    for channel_uuid, session in list(sessions.items()):
        total += await _poll_session(channel_uuid, session)

    return total


async def _poll_loop() -> None:
    while True:
        await asyncio.sleep(POLL_INTERVAL_SECONDS)

        try:
            await _poll_all()
        except Exception as exc:  # noqa: BLE001
            logger.error("Poll loop iteration failed: %s", exc)


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _poll_task

    os.makedirs(SESSIONS_DIR, exist_ok=True)
    logger.info(
        "Instagram service ready with no credentials of its own — "
        "waiting for POST /connect per channel from Laravel."
    )

    _poll_task = asyncio.create_task(_poll_loop())

    yield

    _poll_task.cancel()


app = FastAPI(lifespan=lifespan)


class ConnectRequest(BaseModel):
    channel_uuid: str
    username: str
    password: str


class DisconnectRequest(BaseModel):
    channel_uuid: str


class SendRequest(BaseModel):
    channel_uuid: str
    user_id: str
    text: str


@app.post("/connect")
def connect(body: ConnectRequest) -> dict:
    session = _login(body.channel_uuid, body.username, body.password)

    return {"ok": session.logged_in, "error_type": _error_type(session)}


@app.post("/disconnect")
def disconnect(body: DisconnectRequest) -> dict:
    session = sessions.pop(body.channel_uuid, None)

    if session is not None:
        try:
            session.client.logout()
        except Exception as exc:  # noqa: BLE001 - best-effort; the session is forgotten either way
            logger.warning("[%s] logout() failed (ignored): %s", body.channel_uuid, exc)

    session_file = _session_file(body.channel_uuid)

    if os.path.exists(session_file):
        os.remove(session_file)

    return {"ok": True}


@app.get("/status/{channel_uuid}")
def status(channel_uuid: str) -> dict:
    session = sessions.get(channel_uuid)

    if session is None:
        return {"logged_in": False, "username": None, "challenged": False}

    return {"logged_in": session.logged_in, "username": session.username, "challenged": session.challenged}


@app.post("/send")
def send(body: SendRequest) -> dict:
    session = sessions.get(body.channel_uuid)

    if session is None or not session.logged_in:
        return {"ok": False, "error": "Not connected. Reconnect the channel from the Channels tab."}

    try:
        session.client.direct_send(body.text, [int(body.user_id)])
        return {"ok": True}
    except Exception as exc:  # noqa: BLE001
        logger.error("[%s] direct_send failed for user %s: %s", body.channel_uuid, body.user_id, exc)
        return {"ok": False, "error": str(exc)}


@app.post("/webhook/receive")
async def webhook_receive() -> dict:
    """Manual trigger for one polling pass across every connected account —
    the same work the background loop does every POLL_INTERVAL_SECONDS."""
    forwarded = await _poll_all()

    return {"ok": True, "forwarded": forwarded}


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "accounts_total": len(sessions),
        "accounts_connected": sum(1 for s in sessions.values() if s.logged_in),
    }
