# POS Terminal Enclosure Design

Physical housing design for the FRGS sauna token dispenser terminal.
Covers the display unit, dispenser enclosure, and component mounting.

---

## System Overview

The terminal consists of two physical units:

```
┌─────────────────────────┐
│   Display Unit          │   KKSB Display Stand
│   - Raspberry Pi 5      │   (commercial, unmodified)
│   - Touch Display 2     │
│   - PIR sensor (ext.)   │
└─────────┬───────────────┘
          │ USB (power + data)
          │ 3-wire PIR cable
          │
┌─────────▼───────────────┐
│   Dispenser Unit        │   IP65 metal junction box
│   - Wemos D1 Mini       │   (custom internal layout)
│   - Azkoyen Hopper U-II │
│   - 12V power supply    │
│   - Terminal blocks      │
│   - 2200µF capacitor    │
└─────────────────────────┘
```

---

## 1. Display Unit

### Base: KKSB Display Stand

**Product**: KKSB Display Stand for Raspberry Pi Touch Display 2 with Case for Pi 5
**Material**: Sandblasted black anodized aluminum + powder-coated steel
**Source**: berrybase.de / kksb-cases.com

The KKSB stand is used as-is — no modifications to the case itself.

### Key Features Used

- **GPIO cable cutout** (side panel): routes PIR sensor cable and USB to Wemos
- **Ventilation slots**: passive cooling, supports official Pi heatsink
- **External start button**: integrated power button
- **Adjustable viewing angle**: tilted stand for counter-top POS use

### PIR Sensor Mounting

**Sensor**: HC-SR505 mini PIR (10mm lens, 24mm PCB length, 3-pin header)

**Mounting location**: top edge of the display bezel, centered. The sensor
points outward/downward toward the approach area in front of the counter.

**Mounting options** (in order of preference):

1. **Adhesive clip mount**: small 3D-printed bracket that clips or adheres to
   the top of the KKSB stand. Holds the HC-SR505 with the lens protruding
   forward. Bracket dimensions ~15×12×10mm.

2. **Double-sided VHB tape**: stick the bare HC-SR505 PCB directly to the top
   surface of the stand. Minimal but functional. The sensor PCB is only 10mm
   wide — small enough to be unobtrusive on the black metal surface.

3. **Cable-routed remote mount**: position the PIR sensor separately (e.g. on
   the wall or counter edge) with a longer 3-wire cable. More flexible placement
   but adds visible cabling.

**Cable routing**: 3-wire DuPont jumper cable (5V, GND, signal) exits through
the GPIO cutout on the side of the KKSB case, routes along the stand arm to the
sensor. Use black cable to match the enclosure. Consider cable clips or a small
cable channel for a clean look.

**Important**: The PIR sensor must be mounted **outside** the metal case.
Metal blocks infrared detection completely — the sensor cannot work through
aluminum or steel.

### PIR Sensor Housing

The HC-SR505 is small enough that a housing is optional, but for a finished look:

**Option A — 3D print (recommended)**

Print a minimal clip/sleeve in black PLA or PETG:
- Inner cavity: 11×25mm (snug fit for HC-SR505 PCB)
- Lens opening: 12mm circular cutout
- Cable exit: 5mm hole at rear
- Mounting: two small screw holes or integrated clip for KKSB stand edge
- Material: black PLA (indoor use, matches KKSB aesthetic)

Available STL files on Printables/Thingiverse for HC-SR505 enclosures.
Customize for KKSB mounting as needed.

**Option B — Buy ready-made**

- Etsy: slim 8mm PLA case with screws, ~€5 (search "HC-SR505 case")
- The HC-SR501 cases are too large — ensure you source SR505-specific

**Option C — Heat shrink + adhesive**

For a quick prototype: slide the PCB into a section of large-diameter black heat
shrink tubing, leaving the lens exposed. Adhere to stand surface with VHB tape.

---

## 2. Dispenser Unit

### Enclosure Selection

**Requirements**:
- Houses Azkoyen Hopper U-II (approx. 130×100×100mm)
- Space for Wemos D1 Mini, terminal block, capacitor
- Token loading access (lid or removable panel)
- Token exit slot or chute
- Cable entries (USB, 12V power)
- Robust for club environment (metal preferred)
- IP65 not strictly needed (indoor) but metal = durable + professional

### Recommended Enclosure

**IP65 metal junction box, approx. 200×150×100mm**

**Suggested products** (German suppliers):

| Source | Product | Approx. size | Price |
|--------|---------|-------------|-------|
| Schaltschrank-Xpress | Klemmkasten Edelstahl | 200×150×80mm | ~€25 |
| Amazon.de | Nineleaf IP66 Edelstahl | 200×150×100mm | ~€20 |
| eBay.de | Schaltkasten IP65 Stahlblech | 200×150×100mm | ~€15 |

Look for boxes with a **hinged lid** or screw-fastened cover for easy token refilling.

### Internal Layout

```
┌──────────────────────────────────────┐
│  Enclosure (top view, lid removed)   │
│                                      │
│  ┌──────────────────────┐            │
│  │                      │            │
│  │   Azkoyen Hopper     │  ┌──────┐  │
│  │   U-II               │  │Wemos │  │
│  │                      │  │ D1   │  │
│  │   (token bay area)   │  │      │  │
│  │                      │  └──┬───┘  │
│  │                      │     │      │
│  └──────────┬───────────┘  ┌──▼───┐  │
│             │              │ term │  │
│        [token exit]        │ block│  │
│                            └──────┘  │
│                          ┌────┐      │
│                          │cap │      │
│                          │2200│      │
│                          │µF  │      │
│                          └────┘      │
└──────────────────────────────────────┘
```

### Component Mounting

**Azkoyen Hopper U-II**:
- Mount with M3/M4 screws through the base plate of the enclosure
- Hopper must be oriented with the token exit slot aligned to the
  enclosure exit hole
- Ensure the coin bay opening faces upward for refilling when lid is open

**Wemos D1 Mini**:
- Mount on a small standoff (M2.5 nylon standoffs, 10mm) screwed to the
  enclosure wall or base
- Keep away from hopper motor to minimize electrical noise
- USB port must face the cable gland / entry point

**Terminal block**:
- DIN-rail or screw-mount terminal block (5-position minimum)
- Connections: 12V+, GND (shared), Control, Coin, Error/Empty
- Provides a clean breakout point between Wemos wiring and hopper cable

**2200µF capacitor**:
- Mount close to hopper VCC/GND pins (short leads = less inductance)
- 25V rated minimum (for 12V system)
- Secure with cable tie or adhesive pad (electrolytic caps are tall)

### Cable Entries

**Cable glands** (PG7 or M12) — two needed:

1. **USB cable**: Pi → Wemos (power + serial data)
2. **12V DC power**: external adapter → terminal block

Use IP-rated cable glands even for indoor use — they provide strain relief
and prevent cables from being yanked out.

### Token Exit

The enclosure needs a slot or hole where tokens drop out for the member to collect.

**Options**:

1. **Simple slot**: cut or drill a slot in the enclosure base or front panel,
   aligned with the hopper's coin exit. Add a small ramp/chute (bent sheet
   metal or 3D-printed) to guide tokens to a collection tray.

2. **External tray**: a small collection cup or tray mounted below the exit
   slot. Can be 3D-printed or a repurposed small container.

3. **Gravity chute**: if the enclosure is elevated (e.g. shelf-mounted),
   a short tube guides tokens down to counter level.

### Token Loading

Access via the enclosure lid. When opened, the Azkoyen hopper's coin bay
is exposed for refilling. The lid should be secured (screw or latch) to
prevent unauthorized access but easily opened by staff.

---

## 3. WiFi Considerations

The Wemos D1 communicates with the Pi via USB serial, **not WiFi**.
However, if future features require WiFi (OTA firmware updates, direct
health endpoint access), the metal enclosure will block WiFi signals.

### Mitigation Strategies (if WiFi needed later)

1. **Plastic WiFi window**: replace a small section of the enclosure lid
   with a plastic panel (ABS or polycarbonate). The Wemos antenna should
   be positioned directly behind this window.

2. **External antenna**: replace the Wemos D1 Mini with a Wemos D1 Mini Pro
   which has an external antenna connector. Route a small antenna outside
   the metal box via a cable gland.

3. **Strategic positioning**: mount the Wemos near a cable gland opening
   where the metal shielding has a gap. Not reliable but may work for
   short-range communication with the Pi sitting nearby.

For the current USB-serial architecture, WiFi is not needed and the metal
enclosure is not a problem.

---

## 4. Electrical Safety

### Grounding

- The metal enclosure should be connected to protective earth if the 12V
  power supply has an earth pin
- If using a Class II (double-insulated) 12V adapter, the enclosure does
  not need earthing, but bonding it is still good practice

### Wire Routing

- Keep 12V power wires separated from signal wires inside the enclosure
- Use the terminal block as a central breakout — no loose wire splices
- All connections via screw terminals or soldered + heat-shrink joints
- No exposed mains voltage inside the enclosure (12V adapter is external)

### Thermal

- The Azkoyen hopper motor generates some heat during operation
- The Wemos generates negligible heat
- For an indoor club environment, passive cooling via the metal enclosure
  is sufficient — no ventilation holes needed
- If the enclosure is in direct sunlight (unlikely for indoor POS), add
  small ventilation holes with IP-rated mesh covers

---

## 5. Physical Placement

### Counter-Top Configuration (recommended)

```
         ┌─────────────┐
         │  KKSB Stand  │  ← on counter, facing member
         │  + Display   │
         │  + PIR       │
         └──────┬──────┘
                │ USB + PIR cable (short, 30-50cm)
         ┌──────▼──────┐
         │  Dispenser   │  ← behind/below counter
         │  Enclosure   │
         │  + token tray│  ← accessible to member
         └─────────────┘
```

The display sits on the counter at eye level. The dispenser box sits
behind or below the counter. Tokens exit into a tray accessible to the member.

### Shelf/Wall Configuration (alternative)

Display mounted on wall or shelf bracket. Dispenser box mounted on same shelf
or below. Longer USB cable may be needed (max 2m for reliable USB 2.0 serial).

---

## 6. Bill of Materials (enclosure-specific)

| Item | Qty | Notes |
|------|-----|-------|
| KKSB Display Stand + Pi 5 Case | 1 | berrybase.de |
| Raspberry Pi 5 | 1 | |
| Raspberry Pi Touch Display 2 (7") | 1 | |
| HC-SR505 mini PIR sensor | 1 | Amazon.de / eBay.de |
| PIR mount (3D-printed or adhesive) | 1 | Black PLA/PETG |
| 3-wire DuPont cable (F-F, 30cm) | 1 | For PIR connection |
| IP65 metal junction box (~200×150×100mm) | 1 | Schaltschrank-Xpress / Amazon.de |
| PG7 cable glands | 2 | USB + 12V DC entry |
| M2.5 nylon standoffs (10mm) | 4 | Wemos mounting |
| M3 or M4 screws + nuts | 4 | Hopper mounting |
| 5-position screw terminal block | 1 | Signal breakout |
| USB A-to-Micro cable (30-50cm) | 1 | Pi → Wemos |
| 12V 2A DC adapter | 1 | Hopper power |
| 2200µF 25V electrolytic capacitor | 1 | Startup surge buffer |
| Assorted hookup wire (22 AWG) | 1m | Internal wiring |
| Cable ties | bag | Cable management |
| DIN rail (optional, 35mm, short) | 1 | Terminal block mount |

---

## 7. Assembly Sequence

1. **Display unit**: Assemble Pi 5 + Touch Display 2 into KKSB stand per
   KKSB instructions. Route GPIO ribbon cable through side cutout.

2. **PIR sensor**: Connect 3-wire cable to HC-SR505 (VCC→5V, GND→GND,
   OUT→GPIO17). Route cable through KKSB GPIO cutout. Mount sensor on top
   of stand.

3. **Dispenser enclosure**: Drill/cut mounting holes in junction box for:
   - Hopper base screws (4× M3/M4)
   - Wemos standoffs (4× M2.5)
   - Cable gland holes (2× PG7, typically 12.5mm drill)
   - Token exit slot (size depends on token diameter + 2mm clearance)

4. **Mount hopper**: Secure Azkoyen Hopper U-II to enclosure base. Align
   token exit with enclosure slot. Load a few test tokens.

5. **Wire terminal block**: Connect 12V+, GND, and signal wires between
   terminal block, hopper connector, and Wemos GPIOs per the wiring
   schematic (see dispenser-protocol.md).

6. **Mount Wemos**: Secure on nylon standoffs. Connect USB cable through
   cable gland. Connect signal wires to terminal block.

7. **Capacitor**: Solder or connect 2200µF cap across 12V+ and GND on
   the terminal block, as close to the hopper power pins as practical.

8. **Cable glands**: Install and tighten around USB and 12V cables.

9. **Test**: Power on, verify Wemos responds to `GET /health`. Run test
   dispense. Verify PIR triggers display wake.

10. **Close and secure**: Close enclosure lid, tighten screws. Position
    units in final counter-top arrangement.
