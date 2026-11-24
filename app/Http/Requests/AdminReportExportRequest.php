<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The same filters as the report, and which table to download.
 */
class AdminReportExportRequest extends AdminReportRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'type' => ['required', Rule::in(['days', 'users'])],
        ];
    }

    /**
     * @return 'days'|'users'
     */
    public function type(): string
    {
        return $this->validated('type');
    }
}
