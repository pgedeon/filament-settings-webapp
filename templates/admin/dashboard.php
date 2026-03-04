<div class="fsw-admin-wrap">
    <h1>Filament Settings Web App</h1>
    
    <div class="fsw-dashboard-stats" style="display: flex; gap: 2rem; margin: 1.5rem 0;">
        <div class="fsw-stat">
            <strong>Printers:</strong> <?php echo $this->count_table('fsw_printers'); ?>
        </div>
        <div class="fsw-stat">
            <strong>Filaments:</strong> <?php echo $this->count_table('fsw_filament_products'); ?>
        </div>
        <div class="fsw-stat">
            <strong>Settings:</strong> <?php echo $this->count_table('fsw_settings', "status = 'active'"); ?>
        </div>
        <div class="fsw-stat">
            <strong>Sources:</strong> <?php echo $this->count_table('fsw_sources'); ?>
        </div>
    </div>
    
    <h2>Quick Links</h2>
    <ul>
        <li><a href="<?php echo admin_url('admin.php?page=filament-settings-printers'); ?>">Manage Printers</a></li>
        <li><a href="<?php echo admin_url('admin.php?page=filament-settings-filaments'); ?>">Manage Filaments</a></li>
        <li><a href="<?php echo admin_url('admin.php?page=filament-settings-list'); ?>">View Settings</a></li>
        <li><a href="<?php echo admin_url('admin.php?page=filament-settings-collector'); ?>">Run Collector</a></li>
    </ul>
    
    <h2>Instructions</h2>
    <ol>
        <li>Add printers via Printers page</li>
        <li>Add filament products via Filaments page</li>
        <li>Run collector to populate settings from external sources (or add manually)</li>
        <li>Use shortcode <code>[filament_settings_webapp]</code> on any page to display the frontend app</li>
    </ol>
</div>
