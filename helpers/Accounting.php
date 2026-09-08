<?php
/**
 * Helpers comptabilité : formats monétaires, libellés, conversions.
 */
class Accounting
{
    public const CURRENCY = 'BIF';

    /** 1234567.5 -> "1 234 567,50" */
    public static function money(float|string|null $amount, bool $withCurrency = false): string
    {
        $formatted = number_format((float)$amount, 2, ',', "\xC2\xA0");
        return $withCurrency ? $formatted . "\xC2\xA0" . self::CURRENCY : $formatted;
    }

    /** Solde signé : positif = débiteur, négatif = créditeur. */
    public static function signedBalance(float $amount): string
    {
        if (abs($amount) < 0.005) {
            return '—';
        }
        return self::money(abs($amount)) . ($amount > 0 ? ' D' : ' C');
    }

    public static function dateFr(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
        return $dt ? $dt->format('d/m/Y') : htmlspecialchars($date);
    }

    /** Convertit une saisie fr (1 234,56 / 1234.56) en float, ou null si invalide. */
    public static function parseAmount(string $value): ?float
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        $v = str_replace(["\xC2\xA0", ' '], '', $v);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = strrpos($v, ',') > strrpos($v, '.')
                ? str_replace('.', '', $v)
                : str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float)$v : null;
    }

    public static function classLabel(int $class): string
    {
        return AccountingAccount::CLASS_LABELS[$class] ?? ('Classe ' . $class);
    }

    public static function accountTypeLabel(string $type): string
    {
        return [
            'general'  => 'Général',
            'client'   => 'Client',
            'supplier' => 'Fournisseur',
            'bank'     => 'Banque',
            'cash'     => 'Caisse',
            'other'    => 'Divers',
        ][$type] ?? $type;
    }

    public static function statusLabel(string $status): string
    {
        return ['draft' => 'Brouillon', 'validated' => 'Validée'][$status] ?? $status;
    }

    /** Génère un fichier CSV téléchargeable et termine le script. */
    public static function downloadCsv(string $filename, array $headers, array $rows): never
    {
        if (ob_get_length()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 pour Excel FR + séparateur point-virgule
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
}
