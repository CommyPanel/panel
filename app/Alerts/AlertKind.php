<?php

namespace App\Alerts;

/**
 * The kinds of place a message can go to.
 */
enum AlertKind: string
{
    case Slack = 'slack';
    case Discord = 'discord';
    case Teams = 'teams';
    case Ntfy = 'ntfy';
    case Telegram = 'telegram';
    case Json = 'json';

    public const TELEGRAM_HOST = 'api.telegram.org';

    public function label(): string
    {
        return match ($this) {
            self::Slack => __('Slack (also Mattermost and Rocket.Chat)'),
            self::Discord => __('Discord'),
            self::Teams => __('Microsoft Teams'),
            self::Ntfy => __('ntfy'),
            self::Telegram => __('Telegram'),
            self::Json => __('Generic JSON webhook'),
        };
    }

    /**
     * What the secret field of this kind holds.
     */
    public function addressLabel(): string
    {
        return match ($this) {
            self::Slack, self::Discord => __('Webhook address'),
            self::Teams => __('Workflow webhook address'),
            self::Ntfy => __('Topic address'),
            self::Telegram => __('Bot token'),
            self::Json => __('Webhook address'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Slack => __('An incoming webhook, such as https://hooks.slack.com/services/...'),
            self::Discord => __('A channel webhook, such as https://discord.com/api/webhooks/...'),
            self::Teams => __('The address of a Workflows webhook that posts to a channel.'),
            self::Ntfy => __('The full address of a topic, such as https://ntfy.sh/my-secret-topic.'),
            self::Telegram => __('The token BotFather gave you, such as 123456:ABC-DEF.... The bot must be able to write to the chat.'),
            self::Json => __('Any https address that accepts a JSON POST.'),
        };
    }

    public function needsChatId(): bool
    {
        return $this === self::Telegram;
    }
}
