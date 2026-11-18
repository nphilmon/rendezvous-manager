<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuthUser;

/** Définition unique de la navigation et des modules (aucun lien sans page). */
final class Navigation
{
    /**
     * key => [label, href, icon, permission|null, phase de livraison|null si disponible]
     * @return array<string,array{label:string,href:string,icon:string,perm:?string,phase:?int,group:string}>
     */
    public static function items(): array
    {
        return [
            'dashboard'    => ['label' => 'Tableau de bord', 'href' => '/', 'icon' => 'home', 'perm' => null, 'phase' => null, 'group' => 'main'],
            'calendar'     => ['label' => 'Calendrier', 'href' => '/calendar', 'icon' => 'calendar', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'main'],
            'mine'         => ['label' => 'Mes rendez-vous', 'href' => '/appointments/mine', 'icon' => 'user', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'appointments'],
            'all'          => ['label' => 'Tous les rendez-vous', 'href' => '/appointments', 'icon' => 'list', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'appointments'],
            'today'        => ['label' => 'Aujourd\'hui', 'href' => '/appointments/today', 'icon' => 'sun', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'appointments'],
            'upcoming'     => ['label' => 'À venir', 'href' => '/appointments/upcoming', 'icon' => 'clock', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'appointments'],
            'contacts'     => ['label' => 'Contacts', 'href' => '/contacts', 'icon' => 'book', 'perm' => 'contacts.read', 'phase' => null, 'group' => 'data'],
            'categories'   => ['label' => 'Catégories', 'href' => '/categories', 'icon' => 'tag', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'data'],
            'calendars'    => ['label' => 'Calendriers', 'href' => '/calendars', 'icon' => 'layers', 'perm' => 'appointments.read', 'phase' => null, 'group' => 'data'],
            'notifications' => ['label' => 'Notifications', 'href' => '/notifications', 'icon' => 'bell', 'perm' => null, 'phase' => null, 'group' => 'data'],
            'transfer'     => ['label' => 'Import / export', 'href' => '/import-export', 'icon' => 'download', 'perm' => 'data.export', 'phase' => null, 'group' => 'data'],
            'history'      => ['label' => 'Historique', 'href' => '/history', 'icon' => 'history', 'perm' => 'appointments.read', 'phase' => 17, 'group' => 'data'],
            'reports'      => ['label' => 'Rapports', 'href' => '/reports', 'icon' => 'chart', 'perm' => 'reports.view', 'phase' => 17, 'group' => 'admin'],
            'users'        => ['label' => 'Utilisateurs', 'href' => '/users', 'icon' => 'users', 'perm' => 'users.manage', 'phase' => 16, 'group' => 'admin'],
            'settings'     => ['label' => 'Paramètres', 'href' => '/settings', 'icon' => 'cog', 'perm' => null, 'phase' => null, 'group' => 'admin'],
        ];
    }

    /** @return array<string,array{label:string,href:string,icon:string,perm:?string,phase:?int,group:string}> */
    public static function visibleTo(AuthUser $u): array
    {
        return array_filter(self::items(), static fn ($i) => $i['perm'] === null || $u->can($i['perm']));
    }
}
