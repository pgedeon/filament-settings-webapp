# Filament Settings Web App - Specification Data Fix Documentation

## Overview
This document describes the bug fix applied to the printer comparison tool's specification data display, where raw JSON objects were showing instead of formatted values.

---

## Problem Description

### Symptoms
When comparing printers on `/3d-printer-comparison/`, specification fields displayed full JSON objects instead of human-readable values:

**Before Fix:**
```
Extruder Type: {"id":"152","maker":"Bambu Lab","model":"...",...}
Frame Type: {"id":"96","maker":"Creality","model":"...",...}
Display Type: {"id":"154","maker":"Elegoo","model":"...",...}
```

**After Fix:**
```
Extruder Type: Bowden
Frame Type: Open Frame
Display Type: 128x64 LCD
```

### Affected Fields
All specification fields that use format functions:
- Extruder Type, Hotend Type, Frame Type, Display Type (enum mappings)
- Enclosure, Heated Chamber, Belt Drive (boolean values)
- Build Volume (computed from x/y/z dimensions)
- All other spec fields in the comparison table

---

## Root Cause Analysis

### Location
File: `assets/compare.js`
Function: `getFieldValue(printer, field)` at line ~482

### The Bug
The function was passing the entire printer object to format functions instead of extracting the specific field value first:

```javascript
// Original buggy code (line 482-495)
function getFieldValue(printer, field) {
    let value;
    if (field.format) {
        value = field.format(printer);      // ← BUG: passes whole printer object
    } else {
        value = printer[field.key];
    }
    // Handle object values (convert to string)
    if (value && typeof value === 'object') {
        value = value.label || value.value || JSON.stringify(value);
    }
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    return value + (field.suffix || '');
}
```

### Why It Broke
Format functions like `formatEnum()` expect a simple primitive value:

```javascript
function formatEnum(map, value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    // Handle object values as fallback
    if (typeof value === 'object' && value !== null) {
        if (value.label !== undefined) return value.label;
        if (value.value !== undefined) return value.value;
        return JSON.stringify(value);  // ← This was being called for all fields!
    }
    return map[value] || String(value);
}
```

When `getFieldValue()` passed the entire printer object:
1. `formatEnum()` received `{"id":"152","maker":"Bambu Lab",...}` instead of `'bowden'`
2. It fell into the object handling branch
3. Returned `JSON.stringify(printer)` → full JSON string in table cell

### Special Case: Build Volume
The `build_volume` field is computed from three separate properties (`build_volume_x_mm`, `build_volume_y_mm`, `build_volume_z_mm`). Its format function needs the whole printer object:

```javascript
function formatBuildVolume(printer) {
    const x = printer.build_volume_x_mm;
    const y = printer.build_volume_y_mm;
    const z = printer.build_volume_z_mm;
    if (!x || !y || !z) return '—';
    return `${x} × ${y} × ${z} mm`;
}
```

So the fix needed to handle this special case.

---

## Solution Applied

### Code Change
Modified `getFieldValue()` to extract raw value first, then apply formatting:

```javascript
// Fixed code (line 482-497)
function getFieldValue(printer, field) {
    let rawValue;
    // Special case: build_volume needs the whole printer to compute from x/y/z
    if (field.key === 'build_volume') {
        rawValue = printer;      // Pass whole object for computed fields
    } else {
        rawValue = printer[field.key];  // Extract specific property
    }
    
    let value;
    if (field.format) {
        value = field.format(rawValue);   // ← Now passes correct type
    } else {
        value = rawValue;
    }
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    return value + (field.suffix || '');
}
```

### Key Changes
1. **Extract raw value first** - Check `field.key` to determine what to pass
2. **Special handling for computed fields** - `build_volume` gets whole printer object
3. **All other fields get primitive values** - e.g., `'bowden'`, `'corexy'`, `'0'`, `'1'`
4. **Format functions receive correct input type** - No more JSON strings in output

### Files Modified
- `assets/compare.js` (line 482-497) - Main fix for data formatting
- `templates/frontend/compare.php` - Updated to include all required DOM elements

---

## Format Functions Reference

The comparison tool uses several format functions:

### Enum Mapping (`formatEnum`)
Maps database values to human-readable labels:
```javascript
// Example: extruder_type mapping
bowden → "Bowden"
direct → "Direct"
"" → "—"
```

**Fields using this:**
- `extruder_type`, `hotend_type`, `frame_type`, `display_type`
- `autolevel_type`, `build_surface_type`

### Boolean Formatting (`formatBool`)
Converts 0/1/null to Yes/No/Dash:
```javascript
1 → "Yes"
0 → "No"
null → "—"
```

**Fields using this:**
- `enclosure`, `heated_enclosure`, `belt_drive`
- `wifi_enabled`, `ethernet_enabled`, `usb_media`
- All other yes/no features

### Build Volume (`formatBuildVolume`)
Computes from three dimensions:
```javascript
build_volume_x_mm, build_volume_y_mm, build_volume_z_mm → "256 × 256 × 256 mm"
```

---

## Testing & Verification

### Manual Test Steps
1. Navigate to `/3d-printer-comparison/`
2. Search for and select at least 2 printers
3. Click "Compare Selected" button
4. Verify all specification fields show formatted values:
   - ✅ No JSON objects in cells
   - ✅ Boolean fields show "Yes", "No", or "—"
   - ✅ Enum fields show mapped labels (e.g., "Bowden")
   - ✅ Build volume shows dimensions with units

### Browser Console Check
Open DevTools → Console and verify no errors:
- No `TypeError: Cannot read properties of null`
- No `JSON.stringify` output in table cells
- All format functions execute without exceptions

---

## Related Fixes (Same Session)

### Template Structure Fix
Updated `templates/frontend/compare.php` to include all required DOM elements:
- Added missing `#fsw-header-cards`, `#fsw-sections`
- Ensured `#fsw-compare-button`, `#fsw-diff-toggle`, `#fsw-share-link` exist
- Fixed class names from BEM-style back to original selectors

### Data Flow Fix
The comparison API endpoint (`/wp-json/fsw/v1/printers/compare`) returns full printer objects. The fix ensures proper extraction of individual field values before formatting.

---

## Deployment Notes

**Date:** March 3, 2026
**Version:** Filament Settings Web App v1.1+
**Files Deployed:**
- `assets/compare.js` - Fixed getFieldValue function
- `templates/frontend/compare.php` - Complete template with all elements

**Cache Clearing Required:**
- Browser cache (hard refresh: Ctrl+Shift+R)
- WordPress page cache (if using caching plugin)
- CDN cache (Cloudflare, etc.)

---

## Future Improvements

### Potential Enhancements
1. **TypeScript migration** - Add type safety for format functions
2. **Unit tests** - Test getFieldValue with various input types
3. **Configuration-driven formatting** - Move field definitions to JSON config
4. **Internationalization** - Localize all spec labels and values
5. **Error handling** - Graceful fallbacks when API returns unexpected data

### Code Quality Notes
- Consider adding JSDoc comments for format functions
- Extract special cases (build_volume) into a configuration object
- Add validation for printer object structure before formatting

---

## Author & Review

**Fixed by:** Dashboard Architect Agent
**Date:** 2026-03-03
**Session ID:** agent:main:subagent:f730596e-31bb-4e2a-950b-c75e5184782f
**Reviewed by:** Peter Gedeon (user verification)

---

## Quick Reference Card

| Issue | Cause | Fix |
|-------|-------|-----|
| JSON in cells | `getFieldValue()` passed whole printer to format functions | Extract field value first, pass only for special cases |
| Missing compare button | Template missing required DOM elements | Updated template with all IDs |
| Null reference errors | Elements not found at init time | Ensure template includes all selectors |

**Key Insight:** Format functions expect specific input types. `formatEnum()` needs primitives; `formatBuildVolume()` needs the whole object. The fix differentiates based on field key.

---

*Last updated: March 3, 2026*
