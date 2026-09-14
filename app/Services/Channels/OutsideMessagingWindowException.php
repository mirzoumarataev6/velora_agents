<?php

namespace App\Services\Channels;

use RuntimeException;

/**
 * The platform will not carry this message because too long has passed since
 * the customer last wrote.
 *
 * Both Meta platforms enforce a 24-hour window on free-form messages: outside
 * it, WhatsApp accepts only pre-approved templates and Instagram only a tagged
 * human-agent message. This is NOT a transport failure — the credentials are
 * fine, the network is fine, and retrying will not help until the customer
 * writes again.
 *
 * It exists as its own type because the correct handling differs by caller. An
 * operator typing a reply in the inbox needs to be told, in those words, that
 * the customer has to message first; the automatic reply pipeline just logs it
 * and moves on. Previously the WhatsApp driver returned silently here, so the
 * transcript recorded a reply that was never sent and the operator had no way
 * to know.
 */
class OutsideMessagingWindowException extends RuntimeException {}
