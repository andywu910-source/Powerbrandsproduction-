<?php

namespace App\Services\Drive;

use App\Models\DriveFiling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Sheets driver (service account). OFF by default: needs PBP_DRIVE_ENABLED=true,
 * PBP_DRIVE_DRIVER=google, PBP_DRIVE_CREDENTIALS (service-account JSON path) and
 * PBP_DRIVE_FOLDER_ID (a folder in a Shared Drive the service account can edit).
 * Creates one spreadsheet per log per month named <sheet_key>_<YYYY-MM>_v1 inside
 * the folder and only ever appends rows. It never opens Andy's existing files.
 * Not yet exercised against Google (no credentials exist yet).
 */
class GoogleSheetsDriveFiler implements DriveFiler
{
    private ?string $token = null;

    public function file(DriveFiling $filing): array
    {
        $name = $filing->sheet_key.'_'.$filing->period.'_v1';
        $sheetId = $this->spreadsheetFor($name);
        $res = Http::withToken($this->token())->post(
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS",
            ['values' => [array_values($filing->row)]]
        );
        if (! $res->successful()) {
            throw new RuntimeException('Sheets append failed: HTTP '.$res->status());
        }

        return [$sheetId, (string) ($res->json('updates.updatedRange') ?? '')];
    }

    private function spreadsheetFor(string $name): string
    {
        $existing = DB::table('drive_spreadsheets')->where('name', $name)->value('spreadsheet_id');
        if ($existing) {
            return $existing;
        }
        $folder = config('pbp.drive.root_folder_id');
        if (! $folder) {
            throw new RuntimeException('PBP_DRIVE_FOLDER_ID is not set.');
        }
        $res = Http::withToken($this->token())->post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', [
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'parents' => [$folder],
        ]);
        if (! $res->successful() || ! $res->json('id')) {
            throw new RuntimeException('Could not create spreadsheet: HTTP '.$res->status());
        }
        DB::table('drive_spreadsheets')->insert(['name' => $name, 'spreadsheet_id' => $res->json('id'), 'created_at' => now(), 'updated_at' => now()]);

        return $res->json('id');
    }

    private function token(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $path = config('pbp.drive.credentials_path');
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Drive credentials file is not configured.');
        }
        $sa = json_decode((string) file_get_contents($path), true);
        $now = time();
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $b64(json_encode([
            'iss' => $sa['client_email'] ?? '', 'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3000,
        ]));
        if (! openssl_sign("$head.$claim", $sig, $sa['private_key'] ?? '', 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Could not sign the Google token request.');
        }
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claim.".$b64($sig),
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Google token request failed: HTTP '.$res->status());
        }

        return $this->token = (string) $res->json('access_token');
    }
}
