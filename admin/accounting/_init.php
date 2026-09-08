<?php
/**
 * Bootstrap commun des pages du module comptabilité.
 * Définit $pdo, $currentAdmin et charge models + helpers.
 */
$loginRedirect = '../login.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_once __DIR__ . '/../../models/AccountingPeriod.php';
require_once __DIR__ . '/../../models/AccountingAccount.php';
require_once __DIR__ . '/../../models/AccountingJournal.php';
require_once __DIR__ . '/../../models/AccountingThirdParty.php';
require_once __DIR__ . '/../../models/AccountingEntry.php';
require_once __DIR__ . '/../../models/AccountingReport.php';
require_once __DIR__ . '/../../helpers/Accounting.php';

$loadAccountingAssets = true;
$navPrefix = '../';

/** Onglets internes du module. */
function acc_tabs(string $current): void
{
    $tabs = [
        'acc_dashboard'    => ['index.php', 'Tableau de bord'],
        'acc_entries'      => ['entries.php', 'Écritures'],
        'acc_entry_form'   => ['entry_form.php', 'Saisie pièce'],
        'acc_gjournal'     => ['general_journal.php', 'Journal général'],
        'acc_ledger'       => ['ledger.php', 'Grand livre'],
        'acc_balance'      => ['balance.php', 'Balance générale'],
        'acc_income'       => ['income_statement.php', 'Compte de résultat'],
        'acc_bilan'        => ['balance_sheet.php', 'Bilan'],
        'acc_aging'        => ['aging.php', 'Échéancier tiers'],
        'acc_accounts'     => ['accounts.php', 'Plan comptable'],
        'acc_journals'     => ['journals.php', 'Journaux'],
        'acc_third_parties'=> ['third_parties.php', 'Tiers'],
        'acc_periods'      => ['periods.php', 'Exercices'],
    ];
    echo '<div class="filters no-print" style="margin-bottom:20px;">';
    foreach ($tabs as $key => [$href, $label]) {
        $cls = $key === $current ? 'active' : '';
        echo '<a href="' . $href . '" class="' . $cls . '">' . htmlspecialchars($label) . '</a>';
    }
    echo '</div>';
}

/** Affiche les messages flash passés en session après une redirection POST→GET. */
function acc_flash(): void
{
    if (!empty($_SESSION['acc_success'])) {
        echo '<div class="alert success">' . htmlspecialchars($_SESSION['acc_success']) . '</div>';
        unset($_SESSION['acc_success']);
    }
    if (!empty($_SESSION['acc_error'])) {
        echo '<div class="alert error">' . htmlspecialchars($_SESSION['acc_error']) . '</div>';
        unset($_SESSION['acc_error']);
    }
}

function acc_flash_set(string $success = '', string $error = ''): void
{
    if ($success !== '') { $_SESSION['acc_success'] = $success; }
    if ($error !== '')   { $_SESSION['acc_error'] = $error; }
}

/** Exécute un handler POST : capture les exceptions métier en message d'erreur. */
function acc_handle(callable $fn): bool
{
    try {
        $fn();
        return true;
    } catch (InvalidArgumentException | DomainException | RuntimeException | PDOException $e) {
        acc_flash_set('', $e->getMessage());
        return false;
    }
}
