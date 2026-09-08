<?php
/**
 * Référentiel unique des catégories de signalement.
 * Toute nouvelle catégorie s'ajoute UNIQUEMENT ici (API + admin la reconnaîtront automatiquement).
 * Regroupées par famille pour l'affichage (filtres, formulaires) via GROUPS.
 */
class Categories
{
    const LIST = [
        // --- Déchets ---
        'decharge_sauvage'      => ['label' => 'Décharge sauvage',            'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_menagers'      => ['label' => 'Déchets ménagers',            'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_industriels'   => ['label' => 'Déchets industriels',         'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_medicaux'      => ['label' => 'Déchets médicaux',            'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_electroniques' => ['label' => 'Déchets électroniques (e-waste)', 'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_plastiques'    => ['label' => 'Déchets plastiques',          'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'dechets_dangereux'     => ['label' => 'Déchets dangereux',           'group' => 'dechets', 'preferred_authority_type' => 'municipal'],

        // --- Pollution ---
        'pollution_air'      => ['label' => "Pollution de l'air",     'group' => 'pollution', 'preferred_authority_type' => 'ong'],
        'pollution_eau'      => ['label' => "Pollution de l'eau",     'group' => 'pollution', 'preferred_authority_type' => 'ong'],
        'pollution_sonore'   => ['label' => 'Pollution sonore',       'group' => 'pollution', 'preferred_authority_type' => 'municipal'],
        'pollution_sols'     => ['label' => 'Pollution des sols',     'group' => 'pollution', 'preferred_authority_type' => 'ong'],
        'pollution_chimique' => ['label' => 'Pollution chimique',     'group' => 'pollution', 'preferred_authority_type' => 'ong'],

        // --- Voirie et infrastructures ---
        'nid_de_poule'          => ['label' => 'Nid-de-poule',                'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'route_degradee'        => ['label' => 'Route dégradée',              'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'caniveau_bouche'       => ['label' => 'Caniveau bouché',             'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'inondation'            => ['label' => 'Inondation',                  'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'eclairage_defectueux'  => ['label' => 'Éclairage public défectueux', 'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'egout_endommage'       => ['label' => 'Égout endommagé',             'group' => 'voirie', 'preferred_authority_type' => 'municipal'],
        'pont_endommage'        => ['label' => 'Pont endommagé',              'group' => 'voirie', 'preferred_authority_type' => 'municipal'],

        // --- Environnement ---
        'deforestation'          => ['label' => 'Déforestation',                       'group' => 'environnement', 'preferred_authority_type' => 'ong'],
        'feu_de_brousse'         => ['label' => 'Feu de brousse',                      'group' => 'environnement', 'preferred_authority_type' => 'ong'],
        'coupe_illegale_arbres'  => ["label" => "Coupe illégale d'arbres",             'group' => 'environnement', 'preferred_authority_type' => 'ong'],
        'erosion'                => ['label' => 'Érosion',                             'group' => 'environnement', 'preferred_authority_type' => 'ong'],
        'glissement_terrain'     => ['label' => 'Glissement de terrain',               'group' => 'environnement', 'preferred_authority_type' => 'municipal'],
        'deversement_toxique'    => ['label' => 'Déversement toxique',                 'group' => 'environnement', 'preferred_authority_type' => 'ong'],
        'occupation_illegale'    => ["label" => "Occupation illégale d'espace public", 'group' => 'environnement', 'preferred_authority_type' => 'municipal'],

        // --- Rétrocompatibilité (anciens codes utilisés avant l'élargissement) ---
        'dechets_sauvages'   => ['label' => 'Déchets sauvages',   'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'decharge_illegale'  => ['label' => 'Décharge illégale',  'group' => 'dechets', 'preferred_authority_type' => 'municipal'],
        'pollution'          => ['label' => 'Pollution (général)', 'group' => 'pollution', 'preferred_authority_type' => 'ong'],
    ];

    const GROUPS = [
        'dechets'       => 'Déchets',
        'pollution'     => 'Pollution',
        'voirie'        => 'Voirie et infrastructures',
        'environnement' => 'Environnement',
    ];

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::LIST);
    }

    public static function label(string $code): string
    {
        return self::LIST[$code]['label'] ?? $code;
    }

    public static function group(string $code): string
    {
        return self::LIST[$code]['group'] ?? 'dechets';
    }

    public static function preferredAuthorityType(string $code): string
    {
        return self::LIST[$code]['preferred_authority_type'] ?? 'municipal';
    }

    /** @return string[] codes uniquement, pour <select> et validations */
    public static function codes(): array
    {
        return array_keys(self::LIST);
    }

    /** @return array codes groupés par famille, ex: ['dechets' => ['decharge_sauvage', ...], ...] */
    public static function codesByGroup(): array
    {
        $out = [];
        foreach (self::LIST as $code => $info) {
            $out[$info['group']][] = $code;
        }
        return $out;
    }
}

