<?php

namespace App\Http\Requests\Concerns;

trait RequiresActionRemarkBody
{
    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge([
                'body' => trim((string) $this->input('body')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionRemarkBodyRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function actionRemarkBodyAttributes(): array
    {
        return [
            'body' => 'remark',
        ];
    }
}
