<?php

namespace App\Reports;

use App\Models\User;
use App\Support\Auth\IpCountry;
use App\Support\Auth\LoginLog;
use App\Support\CompanyTime;
use App\Support\Format;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Login history (report.login_history.view, ADMIN and AUDITOR): every sign-in
 * attempt from login_log, successful or not, newest first. Not tied to a
 * warehouse or customer, so a customer user is never given its permission.
 */
final class LoginHistoryReport extends Report
{
    /** Result filter value: every failed attempt. */
    public const FAILED = 'FAILED';

    public function key(): string
    {
        return 'login-history';
    }

    public function icon(): string
    {
        return 'lock_clock';
    }

    public function filters(): array
    {
        $results = ['' => 'reports.login_history.all_results', self::FAILED => 'reports.login_history.all_failed'];
        foreach (LoginLog::RESULTS as $result) {
            $results[$result] = 'reports.login_history.results.'.$result;
        }

        return [
            Filter::search('reports.login_history.search'),
            ...Filter::dates(),
            Filter::select('result', 'reports.login_history.result', $results),
        ];
    }

    public function columns(): array
    {
        return [
            'when' => ['label' => 'reports.login_history.when'],
            'user' => ['label' => 'reports.login_history.user', 'card' => 'title'],
            'result' => ['label' => 'reports.login_history.result', 'card' => 'badge'],
            'country' => ['label' => 'reports.login_history.country'],
            'ip' => ['label' => 'reports.login_history.ip'],
            'device' => ['label' => 'reports.login_history.device', 'priority' => 2],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $search = mb_strtolower($filters['search']);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return DB::table('login_log as l')
            ->leftJoin('app_user as u', 'u.id', '=', 'l.user_id')
            ->tap(fn ($query) => $this->dates($filters)->whereInstant($query, 'l.created_at', CompanyTime::zone()))
            ->when($filters['result'] === self::FAILED, fn ($query) => $query->where('l.result', '<>', LoginLog::SUCCESS))
            ->when(! in_array($filters['result'], ['', self::FAILED], true), fn ($query) => $query->where('l.result', $filters['result']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(u.full_name) like ?', [$like])
                ->orWhereRaw('l.email like ?', [$like])
                ->orWhereRaw('l.ip_address like ?', [$like])
                ->orWhereRaw('lower(l.country) = ?', [$search])))
            ->select(['l.id', 'l.email', 'l.result', 'l.ip_address', 'l.country', 'l.user_agent', 'l.created_at', 'u.full_name'])
            ->orderByDesc('l.created_at')
            ->orderByDesc('l.id');
    }

    public function row(object $row, array $filters): array
    {
        return [
            'when' => ['text' => Format::dateTime(CompanyTime::local($row->created_at)), 'nowrap' => true],
            // The person when the email is theirs; otherwise what was typed.
            'user' => $row->full_name === null
                ? ['text' => (string) $row->email, 'ltr' => true]
                : ['text' => (string) $row->full_name, 'strong' => true],
            'result' => [
                'text' => __('reports.login_history.results.'.$row->result),
                'tone' => match ($row->result) {
                    LoginLog::SUCCESS => 'completed',
                    LoginLog::THROTTLED => 'pending',
                    default => 'rejected',
                },
            ],
            'country' => $row->country === null ? __('reports.login_history.unknown_country') : IpCountry::name((string) $row->country),
            'ip' => ['text' => (string) $row->ip_address, 'ltr' => true],
            'device' => (string) $row->user_agent,
        ];
    }
}
