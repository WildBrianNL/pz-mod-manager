<?php

return [
    'title' => 'Mods',
    'search' => 'Mods zoeken…',
    'add_placeholder' => 'Steam Workshop-ID, item-URL of collectie-URL',
    'auto_activate' => 'direct aanzetten',
    'auto_activate_hint' => 'Probeert de mod meteen aan te zetten zodat één herstart genoeg is. Wordt na de download automatisch geverifieerd en gecorrigeerd.',
    'auto_update' => [
        'title' => 'Auto-update status',
        'last_checked' => 'Laatste controle: :time',
        'pending_at' => 'Herstart gepland om: :time',
        'summary' => 'Samenvatting: :summary',
        'details_title' => 'Uitgebreide details',
        'no_details' => 'Nog geen details vastgelegd.',
        'verbose_on' => 'Verbose: aan',
        'verbose_off' => 'Verbose: uit',
        'state' => [
            'idle' => 'Ingeschakeld — controleert elke :minutes minuut/minuten op Workshop-updates.',
            'checking' => 'Controleert nu op Steam Workshop-updates…',
            'pending_restart' => 'Updates gevonden. Wacht op het automatische herstartmoment.',
            'restarting' => 'Voert updates door met een serverherstart.',
            'check_failed' => 'Kon online spelers niet bevestigen. De volgende controle probeert opnieuw.',
        ],
    ],

    'stat' => [
        'active' => 'Aan',
        'available' => 'Beschikbaar',
        'restart' => 'Herstart nodig',
        'errors' => 'Mod-fouten',
    ],

    'section' => [
        'active' => 'Aan',
        'active_hint' => '— volgorde = load-order',
        'available' => 'Beschikbaar',
        'available_hint' => '— staat op de server, niet aan',
    ],

    'status' => [
        'active' => 'draait',
        'restart' => 'herstart om te laden',
        'downloading' => 'download bij herstart',
        'failed' => 'server kon hem niet laden',
        'orphan' => 'niet gevonden',
    ],

    'badge' => [
        'build_mismatch' => 'build komt niet overeen',
        'errors' => '{1} :count fout|[2,*] :count fouten',
        'update' => 'update beschikbaar',
        'version' => 'v:version',
        'installed' => 'geïnstalleerd :date',
        'updated' => 'Steam :date',
    ],

    'tooltip' => [
        'bundled' => 'gebundeld — zelfde Workshop-item als :title, verwijderen haalt beide weg',
        'disable' => 'Uitzetten — clients downloaden hem niet meer, bestanden blijven op de server',
        'delete' => 'Verwijderen — haalt de mod én de bestanden van de server',
        'bulk_disable' => 'Alle geselecteerde mods uitschakelen? Clients downloaden ze niet meer, de bestanden blijven op de server staan.',
        'bulk_delete' => 'Alle geselecteerde mods en hun bestanden van de server verwijderen? Dit kan niet ongedaan worden gemaakt.',
        'changelog' => 'Changelog tonen',
    ],

    'changelog' => [
        'title' => 'Changelog — :mod',
        'empty' => 'Steam gaf geen changelog terug (die pagina is rate-limited). Bekijk hem op Steam.',
        'open_steam' => 'Bekijk op Steam',
        'close' => 'Sluiten',
    ],

    'category' => [
        'framework' => 'Framework',
        'interface' => 'Interface',
        'vehicles' => 'Voertuigen',
        'building' => 'Bouwen',
        'items' => 'Items',
        'weapons' => 'Wapens',
        'balance' => 'Balans',
        'map' => 'Kaarten',
        'multiplayer' => 'Multiplayer',
        'other' => 'Overig',
    ],

    'action' => [
        'lock' => 'Vastzetten op deze plek (blijft staan na auto-sort)',
        'unlock' => 'Losmaken',
        'add' => 'Toevoegen',
        'sort' => 'Auto-sorteer',
        'refresh' => 'Vernieuwen',
        'restart' => 'Herstart',
        'restart_now' => 'Nu herstarten',
        'enable' => 'Aanzetten',
        'disable' => 'Uitzetten',
        'delete' => 'Verwijderen',
        'changelog' => 'Changelog',
        'enable_dep' => 'Zet aan',
        'clean_up' => 'Opruimen',
        'add_maps' => 'Toevoegen aan Map=',
    ],

    'alert' => [
        'crash' => 'De laatste start is mislukt: :reason. De server laadt de wereld niet tot dit is opgelost.',
        'missing_dep' => '":dep" is vereist door :mods maar staat niet aan.',
        'missing_dep_uninstalled' => '":dep" is vereist door :mods maar staat niet op deze server — voeg het Workshop-item toe, anders laadt de mod nooit.',
        'orphans' => 'Staat aan maar is niet geïnstalleerd: :mods. Deze regels doen niets.',
        'awaiting_download' => '{1} :count mod is nog niet gedownload — herstart de server.|[2,*] :count mods zijn nog niet gedownload — herstart de server.',
        'needs_restart' => '{1} :count mod staat aan maar is nog niet geladen.|[2,*] :count mods staan aan maar zijn nog niet geladen.',
        'build_mismatch' => 'Deze mods vermelden geen ondersteuning voor build :build: :mods.',
        'maps_missing' => 'Kaart-mods gevonden die niet in Map= staan: :maps.',
        'mod_errors' => 'Deze mods gaven fouten tijdens de laatste start: :mods.',
        'order_changed' => 'De load-order is gewijzigd maar nog niet actief — herstart de server om hem toe te passen.',
        'duplicates' => 'Dubbele regels zijn automatisch verwijderd: :mods.',
        'updates' => '{1} :count mod heeft een nieuwere versie op Steam — herstart de server om bij te werken.|[2,*] :count mods hebben een nieuwere versie op Steam — herstart de server om bij te werken.',
    ],

    'bulk' => [
        'selected' => '{1} :count mod geselecteerd|[2,*] :count mods geselecteerd',
        'select_all' => 'alles hieronder selecteren',
        'enable' => 'Inschakelen',
        'disable' => 'Uitschakelen',
        'delete' => 'Verwijderen',
        'clear' => 'Selectie wissen',
    ],

    'confirm' => [
        'restart' => 'Server nu herstarten? Spelers die online zijn worden losgekoppeld.',
        'disable' => 'Deze mod uitzetten? Clients downloaden hem dan niet meer. De bestanden blijven staan zodat je hem zo weer aan kunt zetten.',
        'delete' => 'Deze mod én de bestanden van de server verwijderen? Dit kan niet ongedaan worden gemaakt.',
    ],

    'empty' => [
        'active' => 'Nog geen mods aan — zet er hieronder een aan.',
        'available' => 'Nog niets op de server. Voeg een mod toe en herstart om te downloaden.',
    ],

    'error' => [
        'config_not_found' => 'Geen serverconfig gevonden. Start de server één keer zodat het .ini-bestand wordt aangemaakt.',
        'config_unreadable' => 'De serverconfig kon niet gelezen worden. Er is niets gewijzigd.',
    ],

    'notify' => [
        'locked' => 'Vastgezet op plek :position. Auto-sort laat hem daar staan.',
        'unlocked' => 'Losgemaakt.',
        'order_stale' => 'Laadvolgorde was verouderd, opnieuw geladen. Probeer het nog eens.',
        'added' => '{1} :count mod klaargezet om te downloaden|[2,*] :count mods klaargezet om te downloaden',
        'added_body' => 'Herstart de server om te downloaden. Daarna verschijnen ze onder "Beschikbaar".',
        'already_added' => 'Dat workshop-item stond er al in.',
        'invalid_id' => 'Geen geldig Workshop-ID gevonden in die invoer.',
        'removed' => 'Verwijderd en bestanden gewist.',
        'bulk_enabled' => '{1} :count mod ingeschakeld|[2,*] :count mods ingeschakeld',
        'bulk_disabled' => '{1} :count mod uitgeschakeld|[2,*] :count mods uitgeschakeld',
        'bulk_removed' => '{1} :count mod verwijderd en bestanden gewist|[2,*] :count mods verwijderd en bestanden gewist',
        'bulk_nothing' => 'Niets te doen: de selectie stond al zo.',
        'disabled' => 'Uitgezet — clients downloaden hem niet meer.',
        'files_kept' => 'Uit de config gehaald, maar de bestanden konden niet gewist worden.',
        'refreshed' => 'Vernieuwd.',
        'sorted' => 'Gesorteerd — frameworks en dependencies bovenaan.',
        'cleaned' => 'Opgeruimd.',
        'maps_added' => 'Kaarten toegevoegd aan de config.',
        'restarting' => 'Herstart aangevraagd.',
        'restart_failed' => 'Herstarten mislukt',
        'config_error' => 'De serverconfig kon niet gelezen worden — er is niets gewijzigd.',
        'auto_activated' => 'Automatisch aangezet na download',
        'repaired_workshop' => 'Hersteld: ontbrekende Workshop-ID’s toegevoegd zodat clients deze mods kunnen downloaden',
    ],
];
