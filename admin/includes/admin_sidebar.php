<?php
$admin_sidebar_active = isset($admin_sidebar_active) ? (string)$admin_sidebar_active : 'dashboard';
?>
<nav class="sidebar admin-sidebar">
    <h2>System Admin</h2>
    <ul>
        <li><a href="dashboard.php" data-tooltip="Control Center" class="<?php echo $admin_sidebar_active === 'dashboard' ? 'active' : ''; ?>"><span class="sidebar-link-text">Control Center</span></a></li>
        <li><a href="modules.php" data-tooltip="Operations Hub" class="<?php echo $admin_sidebar_active === 'modules' ? 'active' : ''; ?>"><span class="sidebar-link-text">Operations Hub</span></a></li>
        <li><a href="portal_access.php" data-tooltip="Portal Access" class="<?php echo $admin_sidebar_active === 'portal_access' ? 'active' : ''; ?>"><span class="sidebar-link-text">Portal Access</span></a></li>
        <li><a href="backup_restore.php" data-tooltip="Backup and Restore" class="<?php echo $admin_sidebar_active === 'backup_restore' ? 'active' : ''; ?>"><span class="sidebar-link-text">Backup/Restore</span></a></li>
        <li><a href="security_events.php" data-tooltip="Security Events" class="<?php echo $admin_sidebar_active === 'security_events' ? 'active' : ''; ?>"><span class="sidebar-link-text">Security Events</span></a></li>
        <li><a href="incident_logbook.php" data-tooltip="Incident Logbook" class="<?php echo $admin_sidebar_active === 'incident_logbook' ? 'active' : ''; ?>"><span class="sidebar-link-text">Incident Logbook</span></a></li>
        <li><a href="system_health.php" data-tooltip="System Health" class="<?php echo $admin_sidebar_active === 'system_health' ? 'active' : ''; ?>"><span class="sidebar-link-text">System Health</span></a></li>
        <li><a href="../index.php" data-tooltip="Main Site"><span class="sidebar-link-text">Main Site</span></a></li>
        <li class="sidebar-logout"><a href="../logout.php" data-tooltip="Logout"><span class="sidebar-link-text">Logout</span></a></li>
    </ul>
</nav>
