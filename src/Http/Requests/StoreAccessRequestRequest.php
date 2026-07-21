<?php

declare(strict_types=1);

namespace DbVault\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new DbVault\Models\AccessRequest submission from
 * resources/js/Views/Requests/Create.vue.
 *
 * The SPA submits a flat grant list — `grants: [{ table, privileges: [] }]`
 * with snake_case top-level keys (target_database, duration_minutes) — rather
 * than the per-table boolean matrix the Inertia variant used. Privileges are
 * validated against config('dbvault.allowed_privileges'); an explicit rule
 * rejects config('dbvault.forbidden_privileges') (DROP/TRIGGER) by name so a
 * forbidden privilege can never reach a DbVault\Models\RequestGrant row even
 * if a client sends one anyway.
 */
class StoreAccessRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->is_active
            && (
                $user->hasRole('developer')
                || $user->hasRole('approver')
                || $user->hasRole('admin')
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedDatabases = array_values(array_unique(array_filter(array_merge(
            [config('dbvault.target_database')],
            config('dbvault.allowed_databases', [])
        ))));

        $allowedPrivileges = array_map('strtoupper', config('dbvault.allowed_privileges', []));

        return [
            'target_database' => ['required', 'string', 'max:64', Rule::in($allowedDatabases)],
            'duration_minutes' => ['required', 'integer', Rule::in(config('dbvault.available_durations', [15, 30, 60, 120, 240]))],
            'reason' => ['required', 'string', 'max:2000'],
            'grants' => ['required', 'array', 'min:1'],
            'grants.*.table' => ['required', 'string', 'max:64'],
            'grants.*.privileges' => ['required', 'array', 'min:1'],
            // Case-insensitively constrain each privilege to the allowed set;
            // forbidden privileges fail here and again, by name, in
            // withValidator() below.
            'grants.*.privileges.*' => ['required', 'string', Rule::in($this->caseInsensitivePrivileges($allowedPrivileges))],
        ];
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $forbidden = array_map('strtoupper', config('dbvault.forbidden_privileges', []));

            foreach ((array) $this->input('grants', []) as $index => $grant) {
                foreach ((array) ($grant['privileges'] ?? []) as $priv => $value) {
                    if (in_array(strtoupper((string) $value), $forbidden, true)) {
                        $validator->errors()->add(
                            "grants.{$index}.privileges.{$priv}",
                            "'{$value}' can never be granted — it is a forbidden privilege and is refused before it ever reaches a request."
                        );
                    }
                }
            }
        });
    }

    /**
     * Accept both upper- and lower-case privilege keywords from the client so
     * the SPA's lowercase matrix keys ("select") and canonical MySQL keywords
     * ("SELECT") both validate; the controller upper-cases before persisting.
     *
     * @param  list<string>  $allowedPrivileges
     * @return list<string>
     */
    private function caseInsensitivePrivileges(array $allowedPrivileges): array
    {
        return array_values(array_unique(array_merge(
            $allowedPrivileges,
            array_map('strtolower', $allowedPrivileges),
        )));
    }
}
