<?php
/**
 * Shared sidebar for supervisor area.
 * Before include, set:
 *   $supervisor_sidebar_active — dashboard | feedback | users | content | reports (optional empty string on standalone pages)
 * Optional: $supervisor_sidebar_path_prefix — '' (default) or '../' when including from supervisor/supervisor/
 */
$sup_nav = $supervisor_sidebar_active ?? '';
$p = $supervisor_sidebar_path_prefix ?? '';
$logout_href = ($p === '../') ? '../../logout.php' : '../logout.php';
?>
        <nav class="sidebar">
            <h2>Traffic Supervisor</h2>
            <ul>
                <li><a href="<?php echo htmlspecialchars($p); ?>dashboard.php"<?php echo $sup_nav === 'dashboard' ? ' class="active"' : ''; ?>>Dashboard</a></li>
                <li><a href="<?php echo htmlspecialchars($p); ?>reports.php"<?php echo $sup_nav === 'reports' ? ' class="active"' : ''; ?>>Reports</a></li>
                <li><a href="<?php echo htmlspecialchars($p); ?>feedback.php"<?php echo $sup_nav === 'feedback' ? ' class="active"' : ''; ?>>Motorist Feedback</a></li>
                <li><a href="<?php echo htmlspecialchars($p); ?>user_management.php"<?php echo $sup_nav === 'users' ? ' class="active"' : ''; ?>>User Management</a></li>
                <li><a href="<?php echo htmlspecialchars($p); ?>content_manager.php"<?php echo $sup_nav === 'content' ? ' class="active"' : ''; ?>>Public Content</a></li>
                <li><a href="<?php echo htmlspecialchars($logout_href); ?>">Logout</a></li>
            </ul>
        </nav>
