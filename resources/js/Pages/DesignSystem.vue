<script setup>
/**
 * The design-system gallery.
 *
 * Every component in the library, shown in light, dark and stage side by side.
 * It exists so that a change to a token is reviewable in one place instead of
 * by opening nine screens and remembering what they looked like — and so the
 * modes are compared rather than recalled.
 *
 * Behind auth on purpose: it is a working document for whoever is building the
 * product, not a public page.
 */
import { ref } from 'vue';
import {
    IconChartBar,
    IconClipboardList,
    IconDownload,
    IconInbox,
    IconLock,
    IconMail,
    IconPencil,
    IconPlus,
    IconRobot,
    IconTrash,
    IconUsers,
} from '@tabler/icons-vue';

import AppLayout from '../Layouts/AppLayout.vue';
import ModePreview from '../Components/ModePreview.vue';
import Sparkle from '../Components/Sparkle.vue';
import VeloraMark from '../Components/VeloraMark.vue';

import Avatar from '../Components/ui/Avatar.vue';
import Badge from '../Components/ui/Badge.vue';
import Button from '../Components/ui/Button.vue';
import Card from '../Components/ui/Card.vue';
import ChartCard from '../Components/ui/ChartCard.vue';
import Checkbox from '../Components/ui/Checkbox.vue';
import Drawer from '../Components/ui/Drawer.vue';
import Dropdown from '../Components/ui/Dropdown.vue';
import DropdownItem from '../Components/ui/DropdownItem.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import Funnel from '../Components/ui/Funnel.vue';
import Input from '../Components/ui/Input.vue';
import Modal from '../Components/ui/Modal.vue';
import PageHeader from '../Components/ui/PageHeader.vue';
import Select from '../Components/ui/Select.vue';
import Skeleton from '../Components/ui/Skeleton.vue';
import Sparkline from '../Components/ui/Sparkline.vue';
import StatCard from '../Components/ui/StatCard.vue';
import Switch from '../Components/ui/Switch.vue';
import Table from '../Components/ui/Table.vue';
import Tabs from '../Components/ui/Tabs.vue';
import Textarea from '../Components/ui/Textarea.vue';
import Tooltip from '../Components/ui/Tooltip.vue';
import { toast } from '../toast';

/* --- Live demo state ----------------------------------------------------- */
const text = ref('');
const withError = ref('not-an-email');
const longText = ref('');
const choice = ref('telegram');
const checked = ref(true);
const switched = ref(true);
const tab = ref('overview');
const modalOpen = ref(false);
const drawerOpen = ref(false);

const channelOptions = [
    { value: 'telegram', label: 'Telegram' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'instagram', label: 'Instagram' },
];

const tabs = [
    { value: 'overview', label: 'Обзор' },
    { value: 'knowledge', label: 'База знаний', badge: 12 },
    { value: 'catalog', label: 'Каталог', badge: 248 },
];

/* Demo rows. Tajik and Russian on purpose — the type system has to be reviewed
   in the scripts the product actually ships in, not in Lorem Ipsum. */
const rows = [
    { id: 1, name: 'Мадина Раҳимова', channel: 'Telegram', messages: 1250, value: '1 250,00 сомонӣ' },
    { id: 2, name: 'Иван Петров', channel: 'WhatsApp', messages: 842, value: '3 480,50 сомонӣ' },
    { id: 3, name: 'Ҷамшед Қодиров', channel: 'Instagram', messages: 318, value: '920,00 сомонӣ' },
    { id: 4, name: 'Ольга Сидорова', channel: 'Telegram', messages: 97, value: '15 600,00 сомонӣ' },
];

const columns = [
    { key: 'name', label: 'Клиент', sortable: true },
    { key: 'channel', label: 'Канал', sortable: true },
    { key: 'messages', label: 'Сообщений', sortable: true, align: 'end' },
    { key: 'value', label: 'Сумма', align: 'end' },
];

const funnelStages = [
    { label: 'Сообщения', value: 1840 },
    { label: 'Заявки', value: 412 },
    { label: 'Заказы', value: 168 },
    { label: 'Оплаты', value: 121 },
];

const series = [12, 18, 15, 24, 22, 31, 28, 39, 44, 41, 52, 58];
const falling = [58, 52, 49, 44, 46, 38, 35, 31, 28, 24, 21, 18];

/* The chart palette, read from the tokens so this swatch row cannot drift from
   what the charts actually draw. */
const chartTokens = [
    { token: '--color-chart-1', label: 'Series 1', class: 'bg-chart-1' },
    { token: '--color-chart-2', label: 'Series 2', class: 'bg-chart-2' },
    { token: '--color-chart-3', label: 'Series 3', class: 'bg-chart-3' },
    { token: '--color-chart-4', label: 'Series 4', class: 'bg-chart-4' },
    { token: '--color-chart-5', label: 'Series 5', class: 'bg-chart-5' },
];

const surfaceTokens = [
    { token: 'bg-canvas', class: 'bg-canvas' },
    { token: 'bg-surface', class: 'bg-surface' },
    { token: 'bg-surface-2', class: 'bg-surface-2' },
];
</script>

<template>
    <AppLayout title="Design system">
        <PageHeader
            title="Velora Midnight"
            description="Every component in the library, shown in light, dark and stage. Stage mode is the
                presentation setting: larger type, darker muted text, 2px borders, no shadows or transparency."
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="toast('Скопировано', { type: 'success' })">
                    <IconDownload :size="16" :stroke-width="1.75" />
                    Toast
                </Button>
            </template>
        </PageHeader>

        <!-- ================= FOUNDATIONS ================= -->
        <h2 class="mb-4 text-h2 text-text">Foundations</h2>

        <ModePreview title="Surfaces" description="Separated by a 1px border, never by a background shade alone.">
            <div class="space-y-2">
                <div
                    v-for="surface in surfaceTokens"
                    :key="surface.token"
                    class="rounded-lg border border-border p-3"
                    :class="surface.class"
                >
                    <p class="text-caption font-medium text-muted">{{ surface.token }}</p>
                    <p class="text-body-sm text-text">Body text on this surface</p>
                </div>
            </div>
        </ModePreview>

        <ModePreview title="Type scale" description="Body 15px, nothing under 13px, nothing lighter than 400.">
            <div class="space-y-1.5">
                <p class="text-h1 text-text">Заголовок H1</p>
                <p class="text-h3 text-text">Заголовок H3</p>
                <p class="text-body text-text">Основной текст — 15px</p>
                <p class="text-body-sm text-muted">Вторичный текст — 14px</p>
                <p class="text-overline uppercase text-muted">Overline 13px</p>
                <p class="text-kpi-sm tabular-nums text-text">1 250,00</p>
                <p class="text-body text-text">Ғғ Ӣӣ Ққ Ӯӯ Ҳҳ Ҷҷ — 1 250,00 сомонӣ</p>
            </div>
        </ModePreview>

        <ModePreview title="Chart palette" description="Okabe-Ito derived. Series 3 and 4 need distinct markers — see docs/DESIGN.md.">
            <div class="space-y-2">
                <div v-for="swatch in chartTokens" :key="swatch.token" class="flex items-center gap-2.5">
                    <span class="h-3 w-8 shrink-0 rounded-sm" :class="swatch.class" />
                    <span class="text-caption text-muted">{{ swatch.token }}</span>
                </div>
            </div>
        </ModePreview>

        <ModePreview title="The mark and the AI sparkle" description="#38BDF8 is reserved for AI moments only.">
            <div class="flex items-center gap-4">
                <VeloraMark class="h-9 w-auto" />
                <VeloraMark flat class="h-9 w-auto text-text" />
                <Badge variant="sparkle" :icon="IconRobot">AI ответ</Badge>
                <Sparkle class="h-5 w-5 text-sparkle-ink" label="AI" />
            </div>
        </ModePreview>

        <!-- ================= ACTIONS ================= -->
        <h2 class="mb-4 mt-10 text-h2 text-text">Actions</h2>

        <ModePreview title="Button" description="At most one primary per screen — the 60-30-10 rule.">
            <div class="flex flex-wrap items-center gap-2">
                <Button>Сохранить</Button>
                <Button variant="secondary">Отмена</Button>
                <Button variant="danger">Удалить</Button>
                <Button variant="ghost">Ещё</Button>
                <Button size="sm" variant="secondary">
                    <IconPlus :size="15" :stroke-width="2" />
                    Малый
                </Button>
                <Button loading>Загрузка</Button>
                <Button disabled variant="secondary">Недоступно</Button>
            </div>
        </ModePreview>

        <ModePreview title="Dropdown & Tooltip" description="Tooltips appear on focus as well as hover.">
            <div class="flex items-center gap-3">
                <Dropdown>
                    <template #trigger>
                        <Button variant="secondary" size="sm">Действия</Button>
                    </template>
                    <DropdownItem :icon="IconPencil">Редактировать</DropdownItem>
                    <DropdownItem :icon="IconTrash" danger>Удалить</DropdownItem>
                </Dropdown>

                <Tooltip text="Экспорт в CSV">
                    <Button variant="ghost" size="sm">
                        <IconDownload :size="16" :stroke-width="1.75" />
                    </Button>
                </Tooltip>
            </div>
        </ModePreview>

        <!-- ================= FORMS ================= -->
        <h2 class="mb-4 mt-10 text-h2 text-text">Forms</h2>

        <ModePreview title="Input, Select, Textarea" description="Input borders meet the 3:1 non-text floor; errors are icon + text + colour.">
            <div class="space-y-1">
                <Input id="ds-email" v-model="text" label="Email" :icon="IconMail" placeholder="you@example.com" />
                <Input
                    id="ds-error"
                    v-model="withError"
                    label="С ошибкой"
                    :icon="IconMail"
                    error="Введите корректный email"
                />
                <Input id="ds-pass" label="Пароль" type="password" :icon="IconLock" hint="Минимум 8 символов" />
                <Select id="ds-channel" v-model="choice" label="Канал" :options="channelOptions" />
                <Textarea id="ds-note" v-model="longText" label="Заметка" :rows="3" placeholder="…" />
            </div>
        </ModePreview>

        <ModePreview title="Checkbox & Switch" description="A switch applies immediately; a checkbox commits on submit.">
            <div class="space-y-4">
                <Checkbox id="ds-check" v-model="checked" label="Запомнить меня" hint="На этом устройстве" />
                <Switch
                    id="ds-switch"
                    v-model="switched"
                    label="Агент активен"
                    hint="Отвечает на сообщения клиентов"
                    on-label="Вкл"
                    off-label="Выкл"
                />
            </div>
        </ModePreview>

        <!-- ================= DATA DISPLAY ================= -->
        <h2 class="mb-4 mt-10 text-h2 text-text">Data display</h2>

        <ModePreview title="Badge & Avatar" description="Status is colour + border + text, never colour alone.">
            <div class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <Badge>Нейтральный</Badge>
                    <Badge variant="primary">Активен</Badge>
                    <Badge variant="success">Оплачено</Badge>
                    <Badge variant="warning">Ожидает</Badge>
                    <Badge variant="danger">Ошибка</Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Avatar name="Мадина Раҳимова" size="sm" />
                    <Avatar name="Иван Петров" />
                    <Avatar name="Ҷамшед Қодиров" size="lg" />
                </div>
            </div>
        </ModePreview>

        <ModePreview title="StatCard" description="Trend is an arrow, a sign and a colour. `invert` marks metrics where falling is good.">
            <div class="space-y-3">
                <StatCard label="Сообщений за неделю" :value="1840" :trend="12.4" trend-label="к прошлой" :series="series" :icon="IconUsers" />
                <StatCard label="Среднее время ответа" value="8,2 с" :trend="-18.3" invert trend-label="к прошлой" :series="falling" :icon="IconChartBar" />
            </div>
        </ModePreview>

        <ModePreview title="Table" description="44px rows; numeric columns right-aligned and tabular.">
            <Card flush>
                <Table :columns="columns" :rows="rows" :per-page="3" empty-title="Пока пусто" />
            </Card>
        </ModePreview>

        <ModePreview title="Funnel & Sparkline" description="Bars on a shared baseline — length reads more accurately than area.">
            <div class="space-y-4">
                <Funnel :stages="funnelStages" />
                <div class="text-primary">
                    <Sparkline :values="series" />
                </div>
            </div>
        </ModePreview>

        <ModePreview title="ChartCard" description="The title is an insight, not a label.">
            <ChartCard title="Telegram принёс 62% заявок" subtitle="За последние 30 дней" sparkle>
                <div class="text-chart-1">
                    <Sparkline :values="series" />
                </div>
                <template #legend>
                    <span class="flex items-center gap-2 text-caption text-muted">
                        <span class="h-2.5 w-2.5 rounded-sm bg-channel-telegram" />
                        Telegram
                    </span>
                    <span class="flex items-center gap-2 text-caption text-muted">
                        <span class="h-2.5 w-2.5 rounded-sm bg-channel-whatsapp" />
                        WhatsApp
                    </span>
                </template>
            </ChartCard>
        </ModePreview>

        <!-- ================= NAVIGATION & FEEDBACK ================= -->
        <h2 class="mb-4 mt-10 text-h2 text-text">Navigation & feedback</h2>

        <ModePreview title="Tabs" description="Arrow keys move between tabs; only the selected one is in the tab order.">
            <Tabs v-model="tab" :tabs="tabs" />
        </ModePreview>

        <ModePreview title="Skeleton & EmptyState" description="An empty state says what would be here and how to get it.">
            <div class="space-y-4">
                <div class="space-y-2">
                    <Skeleton class="h-4 w-1/3" />
                    <Skeleton class="h-4 w-full" />
                    <Skeleton class="h-4 w-2/3" />
                </div>

                <Card flush>
                    <EmptyState
                        :icon="IconInbox"
                        title="Нет заявок"
                        description="Когда агент передаст контакт клиента, заявка появится здесь."
                    >
                        <template #action>
                            <Button size="sm">
                                <IconPlus :size="15" :stroke-width="2" />
                                Создать агента
                            </Button>
                        </template>
                    </EmptyState>
                </Card>
            </div>
        </ModePreview>

        <!-- Overlays are rendered through a Teleport to <body>, so they cannot
             sit inside a theme island — a teleported node leaves the island's
             DOM subtree and would always paint in the page's own theme. They
             get ordinary triggers instead. -->
        <section class="mb-8">
            <h3 class="text-h4 text-text">Modal, Drawer & Toast</h3>
            <p class="mt-0.5 max-w-prose text-caption text-muted">
                Overlays teleport to &lt;body&gt;, so they follow the page theme rather than a preview pane —
                switch the theme in the topbar to review them.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                <Button variant="secondary" @click="modalOpen = true">Открыть модальное окно</Button>
                <Button variant="secondary" @click="drawerOpen = true">Открыть панель</Button>
                <Button variant="secondary" @click="toast('Сохранено', { type: 'success' })">Success toast</Button>
                <Button variant="secondary" @click="toast('Не удалось подключиться', { type: 'danger' })">Danger toast</Button>
            </div>
        </section>

        <Modal :open="modalOpen" title="Удалить агента?" description="Это действие нельзя отменить." @close="modalOpen = false">
            <p class="text-body-sm text-muted">
                Все диалоги и заявки этого агента останутся, но он перестанет отвечать клиентам.
            </p>
            <template #footer>
                <Button variant="secondary" @click="modalOpen = false">Отмена</Button>
                <Button variant="danger" @click="modalOpen = false">Удалить</Button>
            </template>
        </Modal>

        <Drawer :open="drawerOpen" title="Фильтры" @close="drawerOpen = false">
            <div class="space-y-1">
                <Select id="ds-drawer-channel" v-model="choice" label="Канал" :options="channelOptions" />
                <Checkbox id="ds-drawer-unread" v-model="checked" label="Только непрочитанные" />
            </div>
            <template #footer>
                <Button variant="secondary" @click="drawerOpen = false">Сбросить</Button>
                <Button @click="drawerOpen = false">Применить</Button>
            </template>
        </Drawer>
    </AppLayout>
</template>
