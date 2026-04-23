#!/usr/bin/env php
<?php
/**
 * cron/recheck_links.php
 *
 * Conservé uniquement pour compatibilité avec d'anciens crontabs.
 *
 * Règle produit actuelle :
 * - le challenge sert une seule fois, au moment de la vérification ;
 * - un lien vérifié reste vérifié après retrait du challenge ;
 * - aucune revérification périodique ne retire un badge ;
 * - si l'utilisateur change l'URL d'un lien, l'API le repasse à vérifier.
 */

declare(strict_types=1);

echo "[recheck_links] Désactivé : les liens vérifiés ne sont plus revérifiés périodiquement.\n";
