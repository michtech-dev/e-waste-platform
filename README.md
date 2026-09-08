<<<<<<< HEAD
# Plateforme de signalement des déchets sauvages

API REST en PHP/PDO/PostgreSQL, consommée par deux applications Cordova :
- **App Citoyen** : signaler, suivre, cumuler des points.
- **App Autorité compétente** : traiter les signalements, consulter le tableau de bord.

## 1. Installation

### Prérequis
- PHP 8.0+ avec extension `pdo_pgsql`
- PostgreSQL 13+
- Apache avec `mod_rewrite` et `mod_headers` activés

### Étapes
```bash
# 1. Créer la base de données
createdb waste_platform

# 2. Importer le schéma, DANS CET ORDRE
psql -d waste_platform -f database/schema.sql
psql -d waste_platform -f database/admin_divisions.sql
psql -d waste_platform -f database/divisions_full_seed.sql

# 3. Configurer la connexion (variables d'environnement recommandées)
export DB_HOST=localhost
export DB_NAME=waste_platform
export DB_USER=waste_app
export DB_PASS=ton_mot_de_passe

# 4. Donner les droits d'écriture au dossier uploads
chmod -R 755 uploads/
```

**Si tu as une base existante** (mise à jour depuis une version antérieure), applique les migrations dans l'ordre chronologique :
```bash
psql -d waste_platform -f migration_add_category.sql
psql -d waste_platform -f database/admin_divisions.sql
psql -d waste_platform -f migration_expand_categories.sql
```

Déploie le dossier à la racine de ton serveur web (ex: `/var/www/html/e-waste-platform/`).

### Divisions administratives du Burundi

L'intégralité de la hiérarchie officielle (5 provinces, 42 communes, 451 zones,
3044 collines/quartiers) issue de la **Loi organique n°1/05 du 16 mars 2023** est
préchargée par `database/admin_divisions.sql` (provinces + communes) et
`database/divisions_full_seed.sql` (zones + collines/quartiers).

Ces totaux ont été vérifiés automatiquement contre ceux annoncés dans la loi
(nombre de zones et de collines par commune) — correspondance exacte, 0 écart.

Si tu dois corriger ou compléter une donnée après coup (faute de frappe dans
le texte source, découpage administratif modifié), utilise la page admin
**Divisions admin.** avec un fichier CSV au format `province,commune,zone,colline,type`
(voir `database/divisions_seed.csv` pour un exemple exhaustif du format).

### Créer un compte agent d'autorité
Il n'y a pas d'inscription publique pour les agents (sécurité). Crée le compte
manuellement en base après un `register.php` classique :
```sql
UPDATE users SET role = 'authority_agent', authority_id = 1 WHERE phone = '+257xxxxxxx';
```


## 2bis. Catégories de signalement

La plateforme couvre désormais 5 types de problèmes civiques, pas seulement les déchets :

| Code | Libellé | Autorité par défaut |
|---|---|---|
| `dechets_sauvages` | Déchets sauvages | Mairie / municipal |
| `decharge_illegale` | Décharge illégale | Mairie / municipal |
| `pollution` | Pollution | ONG |
| `nid_de_poule` | Nid-de-poule | Mairie / municipal |
| `eclairage_defectueux` | Éclairage public défectueux | Mairie / municipal |

La liste est centralisée dans `helpers/Categories.php` — pour ajouter une catégorie,
modifie uniquement ce fichier (API et admin la reconnaîtront automatiquement), **et**
mets à jour la contrainte `CHECK` dans `database/schema.sql` (ou la migration).

**Si tu as déjà une base existante**, exécute la migration avant de redéployer le code :
```bash
psql -U waste_app -d waste_platform -f migration_add_category.sql
```

**Routage automatique** : si le champ `authority_id` n'est pas fourni à la création
d'un signalement, l'API choisit automatiquement l'autorité dont le `type` correspond
le mieux à la catégorie (`pollution` → `ong`, le reste → `municipal`), avec repli sur
la première autorité disponible si aucune ne correspond.

## 2ter. Endpoint mis à jour

`POST /api/reports/create.php` accepte désormais un champ obligatoire `category`
(une des valeurs du tableau ci-dessus) en plus des champs déjà documentés plus bas.



Toutes les réponses sont au format :
```json
{ "success": true, "message": "...", "data": { ... } }
```
Les endpoints protégés attendent le header : `Authorization: Bearer <token>`

### Authentification
| Méthode | Endpoint | Description |
|---|---|---|
| POST | `/api/auth/register.php` | Inscription citoyen (`phone`, `password`, `full_name`, `is_anonymous`) |
| POST | `/api/auth/login.php` | Connexion (`phone`, `password`) → retourne un `token` |
| POST | `/api/auth/logout.php` | Invalide le token courant |

### Signalements
| Méthode | Endpoint | Rôle | Description |
|---|---|---|---|
| POST | `/api/reports/create.php` | Citoyen | Créer un signalement (multipart: `category`, `description`, `latitude`, `longitude`, `severity`, `is_anonymous`, fichiers photo/vidéo) |
| GET | `/api/reports/list.php?status=pending&page=1` | Citoyen / Autorité | Liste filtrée (citoyen: les siens, autorité: sa zone) |
| GET | `/api/reports/detail.php?id=42` | Citoyen / Autorité | Détail + historique de statuts |
| POST | `/api/reports/update_status.php` | Autorité | Change le statut (`report_id`, `status`, `note`) — déclenche notification + points |

### Divisions administratives
| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/divisions/list.php?level=provinces` | Liste des 5 provinces |
| GET | `/api/divisions/list.php?level=communes&parent_id=1` | Communes d'une province |
| GET | `/api/divisions/list.php?level=zones&parent_id=1` | Zones d'une commune |
| GET | `/api/divisions/list.php?level=collines&parent_id=1` | Collines/quartiers d'une zone |

### Suivi public (sans compte)
| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/public/track.php?code=A1B2C3D4` | Statut d'un signalement via son code de suivi — **aucune authentification requise**, pensé pour un partage par SMS/lien |

### Carte / points noirs
| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/hotspots/list.php` | Zones à risque (≥2 signalements), visibles par tous |

### Tableau de bord (mairie)
| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/dashboard/stats.php` | Répartition par statut, temps moyen de résolution, tendance 30j, top zones |

### Notifications
| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/notifications/list.php` | 50 dernières notifications |
| POST | `/api/notifications/mark_read.php` | Marque comme lue (`notification_id`) |

## 3. Exemple d'appel depuis Cordova (JavaScript)

```javascript
// Connexion
fetch('https://ton-domaine.com/api/auth/login.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ phone: '+257xxxxxxx', password: 'motdepasse' })
})
.then(res => res.json())
.then(data => {
  localStorage.setItem('token', data.data.token);
});

// Créer un signalement avec photo (utilise le plugin cordova-plugin-camera
// puis FormData pour l'upload)
const formData = new FormData();
formData.append('description', 'Dépôt sauvage près du marché');
formData.append('latitude', position.coords.latitude);
formData.append('longitude', position.coords.longitude);
formData.append('severity', 'high');
formData.append('is_anonymous', 'false');
formData.append('photo', photoFile);

fetch('https://ton-domaine.com/api/reports/create.php', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer ' + localStorage.getItem('token') },
  body: formData
});
```

## 4. Fonctionnalités couvertes

1. **Signalement avec preuve** → `reports/create.php` (upload photo/vidéo, horodatage `captured_at`, GPS)
2. **Carte en temps réel des points noirs** → `hotspots/list.php` (calcul automatique à chaque signalement)
3. **Suivi de statut** → `reports/detail.php` (historique complet) + `notifications/list.php`
4. **Points/récompenses** → colonne `users.points`, incrémentée automatiquement quand un signalement passe à `resolved`
5. **Anonymat + anti-abus** → `is_anonymous` sur signalement, limite de 10 signalements/24h par utilisateur (`MAX_REPORTS_PER_DAY`)
6. **Tableau de bord mairie** → `dashboard/stats.php`

## 5. Points de vigilance avant mise en production

- Remplacer le mot de passe DB en dur par des variables d'environnement serveur réelles.
- Ajouter une limite de débit (rate limiting) au niveau serveur/proxy, pas seulement applicatif.
- Passer les tokens en HTTPS uniquement (jamais en clair).
- Ajouter une modération humaine des signalements avant validation définitive, pour limiter les faux signalements malgré la limite quotidienne.
- Prévoir une purge/rotation des tokens expirés (`auth_tokens`) via une tâche cron.
=======
# e-waste-platform
plateforme environnementale
>>>>>>> 9550bd5d01c21195b839c05a98dcb6047084eaf5
