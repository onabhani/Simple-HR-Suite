# Kiosk UI/UX Redesign Plan

## Current Problems (from screenshots)

1. **Sidebar wastes 36% of screen width** on iPad — just to show a clock and device name
2. **Camera takes over the screen** with a tiny "Exit" button — disorienting, hard to escape
3. **Error messages are easy to miss** — small red dot + small text, no recovery actions
4. **No employee identity shown** after QR scan — user can't confirm right person was matched
5. **On mobile the sidebar stacks on top**, pushing action buttons below the fold
6. **Buttons are small plain WP buttons** — not touch-friendly for a kiosk environment
7. **No success confirmation screen** — punch result is a brief status bar message

---

## Proposed Design

### Design Principles
- **Touch-first**: Large tap targets (min 56px height), no hover-dependent UI
- **Glanceable**: Walk up → scan → see result → walk away in under 5 seconds
- **Error-resilient**: Errors are full-screen with clear recovery buttons
- **Device-adaptive**: Works on iPad (landscape + portrait) and mobile phones

---

### New Layout: Compact Header + Full Content

Replace the 2-column sidebar with a **compact top header** + full-width content area.

**Menu View (iPad landscape):**
```
┌──────────────────────────────────────────────────────────┐
│  DOFS          04:08 AM          Sat, 18 Ramadan 1447    │  ← Compact teal bar
│                                  Warehouse Kiosk #1      │
├──────────────────────────────────────────────────────────┤
│                                                          │
│                   Good morning!                          │
│                                                          │
│        ┌──────────────────────────────────────┐          │
│        │  ●  Clock In                      →  │          │  ← Green tint, large
│        └──────────────────────────────────────┘          │
│        ┌──────────────────────────────────────┐          │
│        │  ●  Clock Out                     →  │          │  ← Red tint
│        └──────────────────────────────────────┘          │
│        ┌──────────────────────────────────────┐          │
│        │  ●  Start Break                   →  │          │  ← Amber tint
│        └──────────────────────────────────────┘          │
│        ┌──────────────────────────────────────┐          │
│        │  ●  End Break                     →  │          │  ← Blue tint
│        └──────────────────────────────────────┘          │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Menu View (Mobile portrait):**
```
┌────────────────────────┐
│ DOFS        04:08 AM   │  ← Teal header, 2 lines
│ Sat, 18 Ramadan        │
│ Warehouse Kiosk #1     │
├────────────────────────┤
│                        │
│    Good morning!       │
│                        │
│  ┌──────────────────┐  │
│  │ ● Clock In     → │  │
│  └──────────────────┘  │
│  ┌──────────────────┐  │
│  │ ● Clock Out    → │  │
│  └──────────────────┘  │
│  ┌──────────────────┐  │
│  │ ● Start Break  → │  │
│  └──────────────────┘  │
│  ┌──────────────────┐  │
│  │ ● End Break    → │  │
│  └──────────────────┘  │
│                        │
└────────────────────────┘
```

---

### Camera/Scan View

After tapping an action button, slide to the camera view with clear context:

```
┌──────────────────────────────────────────────────────────┐
│  ← Back to Menu           Clock In             04:08 AM  │  ← Teal bar with context
├──────────────────────────────────────────────────────────┤
│                                                          │
│    ┌──────────────────────────────────────────────┐      │
│    │                                              │      │
│    │              📷 Camera Feed                  │      │
│    │                                              │      │
│    │          ┌────────────────┐                  │      │
│    │          │  QR scan zone  │                  │      │  ← Animated corner brackets
│    │          └────────────────┘                  │      │
│    │                                              │      │
│    └──────────────────────────────────────────────┘      │
│                                                          │
│              Show your QR code to the camera             │  ← Clear instruction
│              ◌ Scanning...                               │  ← Animated status
│                                                          │
│    ┌──────────────────────────────────────────────┐      │
│    │              ← Back to Menu                  │      │  ← Large bottom button
│    └──────────────────────────────────────────────┘      │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

---

### Result Screens (new — replaces tiny status bar)

**Success (auto-returns to menu after 4 seconds):**
```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│                    ╭──────────╮                          │
│                    │    ✓     │                          │  ← Large green circle
│                    ╰──────────╯                          │
│                                                          │
│                  Ahmed Al-Rashid                          │  ← Employee name (large)
│                                                          │
│                 Clock In — 04:08 AM                       │  ← Action + time
│                                                          │
│                  ● Clocked In                             │  ← New status badge
│                                                          │
│           ─────────── ◯ ───────────                      │  ← Countdown ring (4s)
│            Returning to menu...                          │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

**Error (stays until user acts):**
```
┌──────────────────────────────────────────────────────────┐
│                                                          │
│                    ╭──────────╮                          │
│                    │    ✗     │                          │  ← Large red circle
│                    ╰──────────╯                          │
│                                                          │
│              Invalid action now.                         │  ← Error message (large)
│          Try a different punch type.                     │
│                                                          │
│        ┌──────────────────────────────────────┐          │
│        │           Try Again                  │          │  ← Re-opens camera
│        └──────────────────────────────────────┘          │
│        ┌──────────────────────────────────────┐          │
│        │         Back to Menu                 │          │  ← Returns to action buttons
│        └──────────────────────────────────────┘          │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

---

## Specific CSS/HTML Changes

### 1. Header Bar (replaces sidebar)
- **Single teal strip** across the top, ~80px tall on iPad, ~100px on mobile
- **Grid layout**: `blog name | clock (large) | date + device`
- On mobile (<600px): stack to 2 rows — clock prominent on first row
- The teal branding stays but stops wasting horizontal space

### 2. Action Buttons (menu view)
- **Full-width card-style buttons** with colored left border accent (4px)
- Min height: **56px mobile**, **64px iPad**
- Layout: colored dot + action label + right arrow chevron
- Max width: 480px, centered
- The "suggested" action gets a **subtle pulsing glow** on its border
- Gap between buttons: **10px**

### 3. Camera View
- **Back button** in header bar (large, tappable, left-aligned)
- **Selected action label** centered in header (so user knows what they're doing)
- Camera fills available space with **12px rounded corners**
- **QR guide overlay**: animated corner brackets centered over the camera
- Instruction text **below** camera, not overlaid on the video
- **Large "Back to Menu" button** fixed at the bottom (not a tiny "Exit" link)

### 4. Result Screen (new view state)
- New `data-view="result"` state alongside existing `menu` and `scan`
- **Success**: green-tinted background, large checkmark icon, employee name, action+time, countdown timer, auto-return after 4s
- **Error**: red-tinted background, large X icon, error message in large text, "Try Again" + "Back to Menu" buttons
- The success screen shows the **employee name** from the scan response — confirms identity

### 5. View Transitions
- Menu → Scan: slide right or fade
- Scan → Result: fade transition
- Result → Menu: fade back (on timer or button click)

---

## View States

```
  ┌──────┐    tap action    ┌──────┐   punch response   ┌────────┐
  │ menu │ ──────────────→  │ scan │ ──────────────────→ │ result │
  └──────┘                  └──────┘                     └────────┘
     ↑          back           │           back/timer        │
     └─────────────────────────┘←────────────────────────────┘
```

---

## File to Modify

**`includes/Modules/Attendance/AttendanceModule.php`** — lines ~2110-2434 (HTML + CSS)
- Replace the 2-column grid HTML with header + content structure
- Rewrite inline `<style>` block for new layout
- Add result screen HTML template (hidden by default)
- Update inline `<script>` to handle 3 view states (menu/scan/result)
- Add countdown timer logic for success auto-return

---

## What Does NOT Change
- All REST API endpoints and punch logic
- QR scanning with jsQR
- Geolocation / geofence enforcement
- Offline mode and IndexedDB caching
- Audio feedback tones
- Flash overlay animation (kept for success feedback)
- Immersive mode veil
- All PHP business logic
