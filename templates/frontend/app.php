<div id="fsw-app" class="fsw-app" role="region" aria-label="Filament Settings Application">
    <header class="fsw-header">
        <h1>3D Printer Filament Settings Database</h1>
        <p class="fsw-subtitle">Find the perfect temperature, speed, and retraction settings for your printer and filament — instantly.</p>
    </header>

    <div class="fsw-filters">
        <div class="fsw-field">
            <label for="fsw-printer"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Printer</label>
            <select id="fsw-printer">
                <option value="">Select your printer...</option>
            </select>
        </div>

        <div class="fsw-field">
            <label for="fsw-filament-type"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Filament Type</label>
            <select id="fsw-filament-type">
                <option value="">Select type...</option>
                <option value="PLA">PLA</option>
                <option value="PETG">PETG</option>
                <option value="ABS">ABS</option>
                <option value="TPU">TPU</option>
                <option value="ASA">ASA</option>
            </select>
        </div>

        <div class="fsw-field">
            <label for="fsw-brand"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg> Brand</label>
            <select id="fsw-brand" disabled>
                <option value="">Select brand...</option>
            </select>
        </div>

        <div class="fsw-field">
            <label for="fsw-nozzle">Nozzle Size</label>
            <select id="fsw-nozzle">
                <option value="0.4">0.4 mm (standard)</option>
                <option value="0.2">0.2 mm (fine)</option>
                <option value="0.6">0.6 mm (large)</option>
                <option value="0.8">0.8 mm (extra large)</option>
            </select>
        </div>

        <div class="fsw-field">
            <label for="fsw-environment">Environment</label>
            <select id="fsw-environment">
                <option value="any">Any</option>
                <option value="open">Open Frame</option>
                <option value="enclosed">Enclosed</option>
            </select>
        </div>

        <button id="fsw-search" class="fsw-search-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Find Settings
        </button>
    </div>

    <div id="fsw-loading" class="fsw-loading" role="status" aria-live="polite" style="display: none;">
        <div class="fsw-spinner"></div>
        <p>Searching database...</p>
    </div>

    <div id="fsw-error" class="fsw-error" style="display: none;"></div>

    <div id="fsw-results" class="fsw-results" aria-live="polite" aria-atomic="true" style="display: none;">
        <div class="fsw-results-header">
            <h2>Recommended Settings</h2>
            <p>Ranked by source quality (manufacturer first) and confidence</p>
        </div>
        <div class="fsw-results-grid"></div>
    </div>

    <div id="fsw-featured" class="fsw-featured" aria-live="polite" aria-atomic="true" style="display: none;">
        <div class="fsw-results-header">
            <h2>Popular Settings</h2>
            <p>Common profiles for popular printers and filaments</p>
        </div>
        <div class="fsw-results-grid"></div>
    </div>
</div>
