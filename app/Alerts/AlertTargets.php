<?php

namespace App\Alerts;

use App\Models\AlertTarget;
use Illuminate\Support\Carbon;

/**
 * What the Notifications page is given: the targets without their addresses, the events and
 * the kinds.
 */
class AlertTargets
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function targets(): array
    {
        return AlertTarget::query()->orderBy('id')->get()->map(fn (AlertTarget $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'kind' => $t->kind->value,
            'kind_label' => $t->kind->label(),
            'host' => $t->host(),
            'chat_id' => $t->maskedChatId(),
            'events' => $t->events,
            'enabled' => $t->enabled,
            'last_ok_at' => $t->last_ok_at instanceof Carbon ? $t->last_ok_at->toIso8601String() : null,
            'last_error' => $t->last_error,
        ])->all();
    }

    /**
     * @return list<array{key: string, label: string, group: string, default_on: bool}>
     */
    public function events(): array
    {
        return array_map(fn (AlertEvent $e): array => [
            'key' => $e->value,
            'label' => $e->label(),
            'group' => $e->group(),
            'default_on' => $e->defaultOn(),
        ], AlertEvent::cases());
    }

    /**
     * @return list<array{value: string, label: string, address_label: string, hint: string, needs_chat_id: bool}>
     */
    public function kinds(): array
    {
        return array_map(fn (AlertKind $k): array => [
            'value' => $k->value,
            'label' => $k->label(),
            'address_label' => $k->addressLabel(),
            'hint' => $k->hint(),
            'needs_chat_id' => $k->needsChatId(),
        ], AlertKind::cases());
    }
}
