(function($) {
    // Show loading state
    function showLoading() {
        $('#fsw-app').attr('aria-busy', 'true');
        $('#fsw-loading').show();
        $('#fsw-results, #fsw-featured').hide();
        $('#fsw-error').hide();
    }

    // Hide loading
    function hideLoading() {
        $('#fsw-app').removeAttr('aria-busy');
        $('#fsw-loading').hide();
    }

    // Show error
    function showError(message, options) {
        options = options || {};
        hideLoading();
        $('#fsw-error').text(message).show();
        if (!options.preserveResults) {
            $('#fsw-results, #fsw-featured').hide();
        }
    }

    // Render settings cards with beautiful design
    function renderCards(settings, container) {
        var html = '';
        var priorityLabels = {
            '100': { label: 'Manufacturer', color: '#10b981' },
            '95': { label: 'OEM', color: '#2563eb' },
            '85': { label: 'Slicer Vendor', color: '#8b5cf6' },
            '80': { label: 'Brand', color: '#f59e0b' },
            '50': { label: 'Community', color: '#64748b' }
        };

        settings.forEach(function(s) {
            var hasConfidence = s.confidence !== null && s.confidence !== undefined && s.confidence !== '' && !isNaN(s.confidence);
            var conf = hasConfidence ? (Number(s.confidence) * 100).toFixed(0) + '%' : 'N/A';
            var source = s.publisher || 'Unknown';
            var notes = s.notes || {};
            if (typeof notes === 'string') {
                try {
                    notes = JSON.parse(notes || '{}');
                } catch (e) {
                    notes = {};
                }
            }
            if (!notes || typeof notes !== 'object') {
                notes = {};
            }
            var settingsJson = s.settings_json || {};
            var priority = priorityLabels[s.source_priority] || { label: 'Unknown', color: '#999' };
            var total_votes = s.total_votes || 0;
            var up_votes = s.up_votes || 0;
            var down_votes = s.down_votes || 0;
            var user_vote = s.user_vote || null;

            // Build settings display
            var settingsDisplay = '';
            if (settingsJson.nozzle_temp) settingsDisplay += '<div class="fsw-setting-item"><span class="label">Nozzle:</span><span class="value">' + settingsJson.nozzle_temp + '°C</span></div>';
            if (settingsJson.bed_temp) settingsDisplay += '<div class="fsw-setting-item"><span class="label">Bed:</span><span class="value">' + settingsJson.bed_temp + '°C</span></div>';
            if (settingsJson.print_speed) settingsDisplay += '<div class="fsw-setting-item"><span class="label">Speed:</span><span class="value">' + settingsJson.print_speed + ' mm/s</span></div>';
            if (settingsJson.retraction_distance) settingsDisplay += '<div class="fsw-setting-item"><span class="label">Retraction:</span><span class="value">' + settingsJson.retraction_distance + ' mm</span></div>';
            if (settingsJson.cooling_percent !== undefined) settingsDisplay += '<div class="fsw-setting-item"><span class="label">Cooling:</span><span class="value">' + settingsJson.cooling_percent + '%</span></div>';

            html += '<div class="fsw-card" data-priority="' + s.source_priority + '" data-setting-id="' + s.id + '" data-user-vote="' + (user_vote || 0) + '">';
            html += '<div class="fsw-card-header">';
            html += '<div class="fsw-card-title">' + s.product_name + '</div>';
            html += '<span class="fsw-priority-badge" style="background: ' + priority.color + '">' + priority.label + '</span>';
            html += '</div>';
            html += '<div class="fsw-card-body">';
            html += '<p class="fsw-printer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> ' + s.maker + ' ' + s.model + '</p>';
            html += '<p class="fsw-brand"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg> ' + s.brand + '</p>';
            html += '<div class="fsw-settings-row">' + settingsDisplay + '</div>';
            if (notes.drying) {
                html += '<div class="fsw-note">💡 Drying: ' + notes.drying + '</div>';
            }
            if (notes.warnings && Array.isArray(notes.warnings) && notes.warnings.length) {
                html += '<div class="fsw-warning">⚠ ' + notes.warnings.join(', ') + '</div>';
            } else if (typeof notes.warnings === 'string' && notes.warnings) {
                html += '<div class="fsw-warning">⚠ ' + notes.warnings + '</div>';
            }
            html += '<div class="fsw-meta">';
            html += '<span class="fsw-source">Source: ' + source + '</span>';
            html += '<span class="fsw-confidence">Confidence: <span class="fsw-confidence-value">' + conf + '</span>' + (hasConfidence ? '<span class="fsw-confidence-tip" title="Higher confidence indicates more consistent results across sources"> ⓘ</span>' : '') + '</span>';
            var updatedDate = s.updated_at ? s.updated_at.substring(0, 10) : 'Unknown';
            html += '<span class="fsw-updated">Updated: ' + updatedDate + '</span>';
            html += '<span class="fsw-samples">Samples: N/A</span>';
            html += '</div>';
            // Voting section
            html += '<div class="fsw-vote-section">';
            html += '<div class="fsw-vote">';
            var upPressed = (user_vote == 1) ? 'true' : 'false';
            var downPressed = (user_vote == -1) ? 'true' : 'false';
            html += '<button class="fsw-vote-btn fsw-up' + (user_vote == 1 ? ' active' : '') + '" data-setting="' + s.id + '" data-vote="1" aria-pressed="' + upPressed + '">👍 <span class="fsw-vote-count">' + up_votes + '</span></button>';
            html += '<button class="fsw-vote-btn fsw-down' + (user_vote == -1 ? ' active' : '') + '" data-setting="' + s.id + '" data-vote="-1" aria-pressed="' + downPressed + '">👎 <span class="fsw-vote-count">' + down_votes + '</span></button>';
            html += '<span class="fsw-total-votes">(' + total_votes + ' vote' + (total_votes !== 1 ? 's' : '') + ')</span>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        });

        container.html(html);
    }

    // Handle vote submission with optimistic UI update
    function submitVote(settingId, vote, button) {
        var $card = button.closest('.fsw-card');
        var $upBtn = $card.find('.fsw-vote-btn.fsw-up');
        var $downBtn = $card.find('.fsw-vote-btn.fsw-down');
        var $totalSpan = $card.find('.fsw-total-votes');
        var $confValue = $card.find('.fsw-confidence-value');
        var $confTip = $card.find('.fsw-confidence-tip');

        if ($card.data('vote-pending')) {
            return;
        }
        $card.data('vote-pending', true);
        $upBtn.prop('disabled', true).attr('aria-disabled', 'true');
        $downBtn.prop('disabled', true).attr('aria-disabled', 'true');

        // Optimistic update: immediately reflect the vote
        var currentUserVote = $card.data('user-vote') || 0;
        var upCount = parseInt($upBtn.find('.fsw-vote-count').text()) || 0;
        var downCount = parseInt($downBtn.find('.fsw-vote-count').text()) || 0;
        var total = parseInt(upCount + downCount);
        var originalState = {
            upCount: upCount,
            downCount: downCount,
            totalText: $totalSpan.text(),
            confText: $confValue.text(),
            userVote: currentUserVote,
            upActive: $upBtn.hasClass('active'),
            downActive: $downBtn.hasClass('active'),
            upPressed: $upBtn.attr('aria-pressed'),
            downPressed: $downBtn.attr('aria-pressed')
        };

        // Remove previous active state
        $upBtn.removeClass('active');
        $downBtn.removeClass('active');

        // If clicking same vote, toggle off (cancel vote)
        var newVote = (currentUserVote == vote) ? 0 : vote;

        if (newVote == 1) {
            $upBtn.addClass('active');
        } else if (newVote == -1) {
            $downBtn.addClass('active');
        }

        // Send request
        $.ajax({
            url: fswData.ajaxUrl + '/vote',
            method: newVote === 0 ? 'DELETE' : (newVote > 0 && currentUserVote === 1) ? 'DELETE' : (newVote > 0 ? 'POST' : 'PATCH'),
            // Note: We'll use POST for new, PATCH for change, but if cancelling, we could also call a delete endpoint. For simplicity, we'll use POST/PATCH with same handler
            // Actually API only supports POST/PATCH, so for cancel we need to send opposite vote or implement DELETE. We'll just send POST/PATCH with the intended final vote.
            // Simpler: always POST the desired final vote (0 means delete, but we don't support that yet). Let's just send the vote, and the backend handles duplicates by updating.
            // We will use POST/PATCH both; if same vote as existing, we can treat as no-op on backend.
            // We'll always POST the newVote (even if 0, skip). But the API expects 1 or -1. So we will not allow toggle-off for now: clicking same vote again will be no-op.
            // Instead, let's implement: clicking active button does nothing (already active). Clicking opposite sends PATCH.
            // For cancel (not implemented in MVP), could add DELETE later.
            // We'll just always POST/PATCH with the vote value; backend handles duplicates as no-op.
            // Revised: we already set newVote above. If it's 0, skip request.
            url: fswData.ajaxUrl + '/vote',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                setting_id: settingId,
                vote: newVote !== 0 ? newVote : vote // if cancel, we'd send 0 which is invalid, so we'll not cancel in MVP; just send vote
            }),
            success: function(response) {
                // Update UI with server response
                var hasConfidence = response.confidence !== null && response.confidence !== undefined && response.confidence !== '' && !isNaN(response.confidence);
                var newConf = hasConfidence ? (Number(response.confidence) * 100).toFixed(0) + '%' : 'N/A';
                $confValue.text(newConf);
                if ($confTip.length) {
                    if (hasConfidence) {
                        $confTip.show();
                    } else {
                        $confTip.hide();
                    }
                }
                $upBtn.find('.fsw-vote-count').text(response.up_votes);
                $downBtn.find('.fsw-vote-count').text(response.down_votes);
                $totalSpan.text('(' + response.total_votes + ' vote' + (response.total_votes !== 1 ? 's' : '') + ')');
                // Update active state and aria-pressed
                var upActive = response.user_vote == 1;
                var downActive = response.user_vote == -1;
                $upBtn.toggleClass('active', upActive).attr('aria-pressed', upActive ? 'true' : 'false');
                $downBtn.toggleClass('active', downActive).attr('aria-pressed', downActive ? 'true' : 'false');
                // Store user_vote on card
                $card.data('user-vote', response.user_vote);
                $card.data('vote-pending', false);
                $upBtn.prop('disabled', false).attr('aria-disabled', 'false');
                $downBtn.prop('disabled', false).attr('aria-disabled', 'false');
            },
            error: function(xhr) {
                // Revert optimistic changes on error
                $upBtn.find('.fsw-vote-count').text(originalState.upCount);
                $downBtn.find('.fsw-vote-count').text(originalState.downCount);
                $totalSpan.text(originalState.totalText);
                $confValue.text(originalState.confText);
                $upBtn.toggleClass('active', originalState.upActive).attr('aria-pressed', originalState.upPressed);
                $downBtn.toggleClass('active', originalState.downActive).attr('aria-pressed', originalState.downPressed);
                $card.data('user-vote', originalState.userVote);
                $card.data('vote-pending', false);
                $upBtn.prop('disabled', false).attr('aria-disabled', 'false');
                $downBtn.prop('disabled', false).attr('aria-disabled', 'false');
                showError('Failed to record vote: ' + (xhr.responseJSON && xhr.responseJSON.message || xhr.statusText), { preserveResults: true });
            }
        });
    }

    // Load selectors
    function loadSelectors() {
        $.ajax({
            url: fswData.ajaxUrl + '/selectors',
            method: 'GET',
            success: function(response) {
                var printers = response.printers || [];
                var types = response.filament_types || [];
                var brands = response.brands || [];

                // Populate printers (sorted)
                printers.sort(function(a, b) {
                    return (a.maker + ' ' + a.model).localeCompare(b.maker + ' ' + b.model);
                }).forEach(function(p) {
                    $('#fsw-printer').append(
                        $('<option>').val(p.id).text(p.maker + ' ' + p.model)
                    );
                });

                // Populate filament types
                types.sort().forEach(function(t) {
                    $('#fsw-filament-type').append(
                        $('<option>').val(t).text(t)
                    );
                });

                // Brand filter on type change
                $('#fsw-filament-type').on('change', function() {
                    var selectedType = $(this).val();
                    $('#fsw-brand').empty().append('<option value="">Select brand...</option>');
                    if (selectedType) {
                        var filtered = (brands || []).filter(function(b) {
                            return b.filament_type === selectedType;
                        });
                        filtered.sort(function(a, b) {
                            return a.brand.localeCompare(b.brand);
                        }).forEach(function(b) {
                            $('#fsw-brand').append(
                                $('<option>').val(b.brand).text(b.brand)
                            );
                        });
                        $('#fsw-brand').prop('disabled', false);
                    } else {
                        $('#fsw-brand').prop('disabled', true);
                    }
                });

                // Auto-load featured settings
                loadFeaturedSettings();
            },
            error: function() {
                showError('Failed to load printer data. Please refresh the page.');
            }
        });
    }

    // Load featured popular settings (PLA for Ender 3, Bambu Lab, etc.)
    function loadFeaturedSettings() {
        // Find popular printer IDs
        var printerSelect = $('#fsw-printer');
        var ender3Id = null, bambuX1Id = null, prusaId = null;

        printerSelect.find('option').each(function() {
            var text = $(this).text().toLowerCase();
            if (text.includes('ender 3') && !text.includes('s1')) ender3Id = $(this).val();
            if (text.includes('x1c') || text.includes('x1')) bambuX1Id = $(this).val();
            if (text.includes('mk4') || text.includes('mk3s')) prusaId = $(this).val();
        });

        var queries = [];
        if (ender3Id) queries.push({ printer_id: ender3Id, filament_type: 'PLA', limit: 3 });
        if (bambuX1Id) queries.push({ printer_id: bambuX1Id, filament_type: 'PLA', limit: 3 });
        if (prusaId) queries.push({ printer_id: prusaId, filament_type: 'PLA', limit: 2 });

        if (queries.length === 0) return;

        var featuredContainer = $('#fsw-featured .fsw-results-grid');
        var allSettings = [];
        var completed = 0;

        queries.forEach(function(q) {
            $.ajax({
                url: fswData.ajaxUrl + '/settings',
                method: 'GET',
                data: q,
                success: function(response) {
                    if (response && response.length) {
                        allSettings = allSettings.concat(response);
                    }
                    completed++;
                    if (completed === queries.length) {
                        // Deduplicate by fingerprint (brand+printer+temps)
                        var seen = new Set();
                        var unique = allSettings.filter(function(s) {
                            var key = s.brand + '_' + s.maker + '_' + s.model + '_' + s.settings_json.nozzle_temp + '_' + s.settings_json.bed_temp;
                            if (seen.has(key)) return false;
                            seen.add(key);
                            return true;
                        });
                        if (unique.length > 0) {
                            renderCards(unique, featuredContainer);
                            $('#fsw-featured').show();
                        }
                    }
                }
            });
        });
    }

    // Perform search
    function performSearch() {
        var printerId = $('#fsw-printer').val();
        var filamentType = $('#fsw-filament-type').val();
        var brand = $('#fsw-brand').val();
        var environment = $('#fsw-environment').val();

        if (!printerId && !filamentType) {
            showError('Please select at least a printer or filament type.');
            return;
        }

        showLoading();

        var data = {
            printer_id: printerId || undefined,
            filament_type: filamentType || undefined,
            filament_product_id: undefined,
            limit: 20
        };

        $.ajax({
            url: fswData.ajaxUrl + '/settings',
            method: 'GET',
            data: data,
            success: function(response) {
                hideLoading();
                if (!response || response.length === 0) {
                    showError('No settings found matching your criteria. Try different filters.');
                } else {
                    var container = $('#fsw-results .fsw-results-grid');
                    renderCards(response, container);
                    $('#fsw-results').show();
                    $('#fsw-featured').hide();
                }
            },
            error: function(xhr) {
                hideLoading();
                var msg = 'Error fetching settings.';
                if (xhr.status === 404) msg = 'No settings found.';
                else if (xhr.status === 500) msg = 'Server error. Please try again later.';
                showError(msg);
            }
        });
    }

    // Initialize
    $(function() {
        loadSelectors();

        $('#fsw-search').on('click', performSearch);

        $('#fsw-filters input, #fsw-filters select').on('keypress', function(e) {
            if (e.which === 13) performSearch();
        });

        // Vote button delegation
        $(document).on('click', '.fsw-vote-btn', function() {
            var $btn = $(this);
            var $card = $btn.closest('.fsw-card');
            var settingId = $btn.data('setting');
            var vote = parseInt($btn.data('vote'));
            submitVote(settingId, vote, $btn);
        });
    });
})(jQuery);
