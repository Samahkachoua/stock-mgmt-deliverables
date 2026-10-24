<?php

namespace App\Support\Drafts;

use App\Support\Transactions\TypeUi;

/**
 * Everything a draft screen needs to know about one kind of draft: how it looks
 * (config/transactions.php `drafts`) and the transaction type it creates when
 * confirmed, whose behaviour (stock in or out, expiry, serials) the draft
 * follows. Duck-types the parts of TypeUi that x-page-header uses.
 */
final class DraftUi
{
    /**
     * @param  array<string, mixed>  $config  config('transactions.drafts.<code>')
     */
    public function __construct(
        public readonly string $code,
        private readonly array $config,
        public readonly TypeUi $type,
    ) {}

    public function slug(): string
    {
        return $this->config['slug'];
    }

    public function icon(): string
    {
        return $this->config['icon'];
    }

    public function prefix(): string
    {
        return $this->config['prefix'];
    }

    public function createPermission(): string
    {
        return $this->config['create'];
    }

    public function confirmPermission(): string
    {
        return $this->config['confirm'];
    }

    /**
     * The app_user column that says whether a confirmer gets an email about
     * these drafts (e.g. notify_new_orders).
     */
    public function notifyColumn(): string
    {
        return $this->config['notify'];
    }

    /**
     * Translated email text for this kind of draft, e.g. mailText('open') → "Open order".
     *
     * @param  array<string, mixed>  $replace
     */
    public function mailText(string $key, array $replace = []): string
    {
        return __('drafts.mail.'.$this->config['lang'].'.'.$key, $replace);
    }

    /**
     * @return array{icon: string, soft: string, strong: string}
     */
    public function colours(): array
    {
        return config('ui.types.'.$this->config['token']);
    }

    /**
     * Translated text for this kind of draft, e.g. label('title') → "Draft receipts".
     *
     * @param  array<string, mixed>  $replace
     */
    public function label(string $key, array $replace = []): string
    {
        return __('drafts.types.'.$this->config['lang'].'.'.$key, $replace);
    }

    public function route(string $page = 'index', mixed $parameters = []): string
    {
        return route($page === 'index' ? $this->slug() : $this->slug().'.'.$page, $parameters);
    }

    /** Stock comes in (receipt): expiry dates typed, serials typed. */
    public function receives(): bool
    {
        return $this->type->postingDirection() === 'IN';
    }
}
