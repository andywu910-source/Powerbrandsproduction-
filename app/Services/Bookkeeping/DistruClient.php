<?php

namespace App\Services\Bookkeeping;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for Distru's public API (https://app.distru.com/public/v1).
 * The API key stays on the server. Page links are followed only when they
 * stay on the configured Distru host.
 */
class DistruClient
{
    public function configured(): bool
    {
        return trim((string) config('bookkeeping.distru.api_key')) !== '';
    }

    /** @return array<int, array<string, mixed>> */
    public function searchBrands(string $query): array
    {
        $this->guard();
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        if (mb_strlen($query) > 80) {
            $query = mb_substr($query, 0, 80);
        }

        $rows = array_merge(
            $this->collect('/companies', ['name' => $query]),
            $this->collect('/companies', ['legal_business_name' => $query]),
        );

        $out = [];
        foreach ($rows as $row) {
            $brand = $this->brand($row);
            if ($brand === null || isset($out[$brand['id']])) {
                continue;
            }
            $name = mb_strtolower($brand['name']);
            $legal = mb_strtolower($brand['legal_name']);
            $needle = mb_strtolower($query);
            if (! str_contains($name, $needle) && ! str_contains($legal, $needle)) {
                continue;
            }
            $out[$brand['id']] = $brand;
        }

        $brands = array_values($out);
        usort($brands, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_slice($brands, 0, 20);
    }

    /** @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function companies(array $ids): array
    {
        $this->guard();
        $ids = $this->ids($ids);
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->collect('/companies', ['ids[]' => $ids]) as $row) {
            $brand = $this->brand($row);
            if ($brand !== null) {
                $out[$brand['id']] = $brand;
            }
        }

        return $out;
    }

    /**
     * Open (not voided, still owed) invoices grouped by company id.
     *
     * @param  array<int, string>  $companyIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function openInvoicesFor(array $companyIds): array
    {
        $this->guard();
        $companyIds = $this->ids($companyIds);
        $grouped = [];
        foreach ($companyIds as $id) {
            $grouped[$id] = [];
        }
        if ($companyIds === []) {
            return [];
        }

        $rows = $this->collect('/invoices', [
            'company_ids[]' => $companyIds,
            'statuses[]' => ['NOT_PAID', 'PARTIALLY_PAID'],
            'is_voided' => 'false',
        ]);

        foreach ($rows as $row) {
            if (! empty($row['voided_datetime'])) {
                continue;
            }
            $companyId = (string) ($row['company']['id'] ?? '');
            if ($companyId === '' || ! isset($grouped[$companyId])) {
                continue;
            }
            $due = $this->amount($row['remaining_amount'] ?? null);
            if ($due <= 0) {
                continue;
            }
            $number = trim((string) ($row['invoice_number'] ?? ''));
            $grouped[$companyId][] = [
                'id' => (string) ($row['id'] ?? ''),
                'number' => $number !== '' ? $number : (string) ($row['id'] ?? ''),
                'date' => $this->dateLabel($row['invoice_datetime'] ?? null),
                'amount_due' => $due,
                'amount_label' => $this->money($due),
            ];
        }

        return $grouped;
    }

    public function invoicePdf(string $invoiceId): string
    {
        $this->guard();
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $invoiceId)) {
            throw new DistruException('That invoice could not be downloaded.');
        }

        $url = $this->base().'/invoices/'.rawurlencode($invoiceId).'/pdf';
        $this->assertDistruUrl($url);
        $response = $this->http()->accept('application/pdf')->timeout(45)->post($url, ['format' => 'binary']);
        $body = $response->body();
        if (! $response->successful() || ! str_starts_with($body, '%PDF-')) {
            throw new DistruException('Distru did not return a PDF for that invoice. Nothing was emailed.');
        }

        return $body;
    }

    private function guard(): void
    {
        if (! $this->configured()) {
            throw new DistruException('Set DISTRU_API_KEY in the server environment. Brand search is off until then.');
        }
    }

    /** @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function collect(string $path, array $query): array
    {
        $url = $this->base().$path;
        $qs = $this->query($query);
        $next = $qs === '' ? $url : $url.'?'.$qs;
        $data = [];

        for ($page = 0; $page < 6 && $next; $page++) {
            $this->assertDistruUrl($next);
            $response = $this->http()->get($next);
            if ($response->status() === 401) {
                throw new DistruException('Distru rejected the API key. Check DISTRU_API_KEY on the server.');
            }
            if (! $response->successful()) {
                throw new DistruException('Distru could not be reached (HTTP '.$response->status().').');
            }
            $json = $response->json();
            if (! is_array($json)) {
                throw new DistruException('Distru returned an unexpected response.');
            }
            foreach ($json['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $data[] = $row;
                }
            }
            $next = is_string($json['next_page'] ?? null) && $json['next_page'] !== '' ? $json['next_page'] : null;
        }

        return $data;
    }

    /** @param  array<string, mixed>  $row */
    private function brand(array $row): ?array
    {
        $id = (string) ($row['id'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $legal = trim((string) ($row['legal_business_name'] ?? ''));
        if ($name === '') {
            $name = $legal !== '' ? $legal : $id;
        }
        $balance = $this->amount($row['outstanding_balance'] ?? null);
        $email = $this->email($row);

        return [
            'id' => $id,
            'name' => $name,
            'legal_name' => $legal,
            'balance' => $balance,
            'balance_label' => $balance > 0 ? $this->money($balance).' open' : 'No open invoices',
            'email' => $email,
            'email_label' => $email ?? 'Not on file',
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function email(array $row): ?string
    {
        foreach (['invoice_email', 'default_email', 'sales_order_email'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    private function amount(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    private function dateLabel(mixed $iso): string
    {
        if (! is_string($iso) || $iso === '') {
            return '';
        }
        try {
            return Carbon::parse($iso)->timezone(config('pbp.site_timezone', 'America/New_York'))->format('m/d/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /** @param  array<string, mixed>  $query */
    private function query(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $item) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    private function assertDistruUrl(string $url): void
    {
        $base = parse_url($this->base()) ?: [];
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $baseHost = strtolower((string) ($base['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || $host !== $baseHost) {
            throw new DistruException('Distru returned an unexpected link. The request was stopped.');
        }
    }

    private function base(): string
    {
        return rtrim((string) config('bookkeeping.distru.base_url'), '/');
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('bookkeeping.distru.api_key'))
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'PowerbrandsProductions-Bookkeeping'])
            ->timeout(20);
    }
}
