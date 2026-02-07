import { useState, useMemo } from "react";

const MEMBERS = Array.from({ length: 115 }, (_, i) => {
  const names = [
    "Müller, Thomas", "Schmidt, Anna", "Weber, Lukas", "Fischer, Marie",
    "Wagner, Felix", "Becker, Sophie", "Hoffmann, Jan", "Koch, Laura",
    "Richter, Maximilian", "Schäfer, Lena", "Krüger, Paul", "Braun, Emilia",
    "Hartmann, David", "Schwarz, Clara", "Zimmermann, Niklas", "Meyer, Lisa",
    "Krause, Tim", "Werner, Julia", "Lehmann, Moritz", "Schulze, Hannah",
    "Klein, Sebastian", "Wolf, Mia", "Peters, Daniel", "Neumann, Lea",
    "Lang, Benjamin", "Huber, Johanna", "Frank, Alexander", "Berger, Sarah",
    "Kaiser, Finn", "Vogel, Amelie"
  ];
  const name = names[i % names.length];
  const hasCard = Math.random() > 0.3;
  const isActive = Math.random() > 0.15;
  const hasSepa = Math.random() > 0.2;
  const year = 2018 + Math.floor(Math.random() * 7);
  const month = String(1 + Math.floor(Math.random() * 12)).padStart(2, "0");
  return {
    id: i + 1,
    name,
    active: isActive,
    sepa: hasSepa,
    cardUid: hasCard ? `04:${String(i * 7 % 99).padStart(2,"0")}:A${String(i % 9)}:${String((i * 3) % 99).padStart(2,"0")}:B${i % 5}` : null,
    memberSince: `${year}-${month}`,
  };
});

const Pill = ({ active, children, onClick }) => (
  <button
    onClick={onClick}
    style={{
      padding: "6px 14px",
      borderRadius: 6,
      border: "none",
      fontSize: 13,
      fontWeight: 500,
      cursor: "pointer",
      transition: "all 0.15s ease",
      background: active ? "#3b82f6" : "rgba(255,255,255,0.06)",
      color: active ? "#fff" : "rgba(255,255,255,0.55)",
    }}
  >
    {children}
  </button>
);

const Badge = ({ color, children }) => (
  <span
    style={{
      display: "inline-flex",
      alignItems: "center",
      gap: 5,
      padding: "3px 10px",
      borderRadius: 99,
      fontSize: 12,
      fontWeight: 500,
      background: color === "green" ? "rgba(34,197,94,0.12)" : color === "red" ? "rgba(239,68,68,0.10)" : "rgba(255,255,255,0.06)",
      color: color === "green" ? "#4ade80" : color === "red" ? "#f87171" : "rgba(255,255,255,0.4)",
    }}
  >
    <span style={{
      width: 6, height: 6, borderRadius: 99,
      background: color === "green" ? "#4ade80" : color === "red" ? "#f87171" : "rgba(255,255,255,0.25)",
    }} />
    {children}
  </span>
);

const SepaIcon = ({ active }) => (
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
    <rect x="1" y="4" width="16" height="10" rx="2" stroke={active ? "#60a5fa" : "rgba(255,255,255,0.15)"} strokeWidth="1.5" fill="none"/>
    <path d="M1 8h16" stroke={active ? "#60a5fa" : "rgba(255,255,255,0.15)"} strokeWidth="1.5"/>
  </svg>
);

export default function MembersPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [card, setCard] = useState("all");
  const [sortField, setSortField] = useState("memberSince");
  const [sortDir, setSortDir] = useState("desc");

  const filtered = useMemo(() => {
    let list = MEMBERS;
    if (search) {
      const q = search.toLowerCase();
      list = list.filter(m => m.name.toLowerCase().includes(q) || (m.cardUid && m.cardUid.includes(q)));
    }
    if (status === "active") list = list.filter(m => m.active);
    if (status === "inactive") list = list.filter(m => !m.active);
    if (card === "with") list = list.filter(m => m.cardUid);
    if (card === "without") list = list.filter(m => !m.cardUid);

    list = [...list].sort((a, b) => {
      let av, bv;
      if (sortField === "name") { av = a.name; bv = b.name; }
      else if (sortField === "cardUid") { av = a.cardUid || ""; bv = b.cardUid || ""; }
      else { av = a.memberSince; bv = b.memberSince; }
      const cmp = av < bv ? -1 : av > bv ? 1 : 0;
      return sortDir === "asc" ? cmp : -cmp;
    });
    return list;
  }, [search, status, card, sortField, sortDir]);

  const toggleSort = (field) => {
    if (sortField === field) setSortDir(d => d === "asc" ? "desc" : "asc");
    else { setSortField(field); setSortDir("asc"); }
  };

  const SortArrow = ({ field }) => {
    if (sortField !== field) return <span style={{ opacity: 0.25, marginLeft: 4 }}>↕</span>;
    return <span style={{ marginLeft: 4, color: "#60a5fa" }}>{sortDir === "asc" ? "↑" : "↓"}</span>;
  };

  const activeFilters = (status !== "all" ? 1 : 0) + (card !== "all" ? 1 : 0) + (search ? 1 : 0);

  return (
    <div style={{
      minHeight: "100vh",
      background: "#0c1221",
      color: "#e2e8f0",
      fontFamily: "'DM Sans', system-ui, sans-serif",
      padding: "32px 40px",
    }}>
      <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

      {/* Header */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 28 }}>
        <div>
          <h1 style={{ fontSize: 26, fontWeight: 700, margin: 0, letterSpacing: "-0.02em" }}>Mitglieder</h1>
          <p style={{ margin: "4px 0 0", fontSize: 14, color: "rgba(255,255,255,0.4)" }}>
            {filtered.length} von {MEMBERS.length} Mitgliedern
          </p>
        </div>
        <button style={{
          display: "flex", alignItems: "center", gap: 7,
          padding: "10px 20px", borderRadius: 8, border: "none",
          background: "#3b82f6", color: "#fff", fontSize: 14, fontWeight: 600,
          cursor: "pointer", transition: "background 0.15s",
        }}>
          <span style={{ fontSize: 18, lineHeight: 1 }}>+</span> Hinzufügen
        </button>
      </div>

      {/* ── Single unified toolbar ── */}
      <div style={{
        display: "flex", alignItems: "center", gap: 16, flexWrap: "wrap",
        padding: "14px 18px",
        background: "rgba(255,255,255,0.03)",
        borderRadius: 10,
        border: "1px solid rgba(255,255,255,0.06)",
        marginBottom: 20,
      }}>
        {/* Search */}
        <div style={{ position: "relative", flex: "0 1 260px", minWidth: 180 }}>
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
            style={{ position: "absolute", left: 10, top: "50%", transform: "translateY(-50%)", opacity: 0.35 }}>
            <circle cx="7" cy="7" r="5.5" stroke="#fff" strokeWidth="1.5"/>
            <path d="M11 11l3.5 3.5" stroke="#fff" strokeWidth="1.5" strokeLinecap="round"/>
          </svg>
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Suchen…"
            style={{
              width: "100%", padding: "8px 12px 8px 32px", borderRadius: 7,
              border: "1px solid rgba(255,255,255,0.08)", background: "rgba(255,255,255,0.04)",
              color: "#e2e8f0", fontSize: 13, outline: "none",
            }}
          />
        </div>

        {/* Divider */}
        <div style={{ width: 1, height: 28, background: "rgba(255,255,255,0.08)" }} />

        {/* Status filter group */}
        <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
          <span style={{ fontSize: 12, color: "rgba(255,255,255,0.35)", marginRight: 4, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.04em" }}>Status</span>
          <Pill active={status === "all"} onClick={() => setStatus("all")}>Alle</Pill>
          <Pill active={status === "active"} onClick={() => setStatus("active")}>Aktiv</Pill>
          <Pill active={status === "inactive"} onClick={() => setStatus("inactive")}>Inaktiv</Pill>
        </div>

        {/* Divider */}
        <div style={{ width: 1, height: 28, background: "rgba(255,255,255,0.08)" }} />

        {/* Card filter group */}
        <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
          <span style={{ fontSize: 12, color: "rgba(255,255,255,0.35)", marginRight: 4, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.04em" }}>Karte</span>
          <Pill active={card === "all"} onClick={() => setCard("all")}>Alle</Pill>
          <Pill active={card === "with"} onClick={() => setCard("with")}>Zugewiesen</Pill>
          <Pill active={card === "without"} onClick={() => setCard("without")}>Keine</Pill>
        </div>

        {/* Clear filters */}
        {activeFilters > 0 && (
          <>
            <div style={{ flex: 1 }} />
            <button
              onClick={() => { setSearch(""); setStatus("all"); setCard("all"); }}
              style={{
                padding: "6px 12px", borderRadius: 6, border: "1px solid rgba(255,255,255,0.08)",
                background: "transparent", color: "rgba(255,255,255,0.45)", fontSize: 12,
                cursor: "pointer", display: "flex", alignItems: "center", gap: 5,
              }}
            >
              <span style={{ fontSize: 14, lineHeight: 1 }}>×</span> Filter zurücksetzen
            </button>
          </>
        )}
      </div>

      {/* Table */}
      <div style={{
        borderRadius: 10,
        border: "1px solid rgba(255,255,255,0.06)",
        overflow: "hidden",
      }}>
        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13.5 }}>
          <thead>
            <tr style={{ background: "rgba(255,255,255,0.03)" }}>
              <th style={thStyle}>Status</th>
              <th style={thStyle}>
                <SepaIcon active={false} />
              </th>
              <th style={{ ...thStyle, cursor: "pointer", userSelect: "none" }} onClick={() => toggleSort("name")}>
                Name <SortArrow field="name" />
              </th>
              <th style={{ ...thStyle, cursor: "pointer", userSelect: "none" }} onClick={() => toggleSort("cardUid")}>
                Karten-UID <SortArrow field="cardUid" />
              </th>
              <th style={{ ...thStyle, cursor: "pointer", userSelect: "none", textAlign: "right" }} onClick={() => toggleSort("memberSince")}>
                Mitglied seit <SortArrow field="memberSince" />
              </th>
              <th style={{ ...thStyle, textAlign: "right", width: 100 }}>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            {filtered.slice(0, 25).map((m, idx) => (
              <tr key={m.id} style={{
                borderTop: "1px solid rgba(255,255,255,0.04)",
                background: idx % 2 === 0 ? "transparent" : "rgba(255,255,255,0.015)",
                transition: "background 0.1s",
              }}
                onMouseEnter={e => e.currentTarget.style.background = "rgba(255,255,255,0.04)"}
                onMouseLeave={e => e.currentTarget.style.background = idx % 2 === 0 ? "transparent" : "rgba(255,255,255,0.015)"}
              >
                <td style={tdStyle}>
                  <Badge color={m.active ? "green" : "red"}>
                    {m.active ? "Aktiv" : "Inaktiv"}
                  </Badge>
                </td>
                <td style={tdStyle}>
                  <SepaIcon active={m.sepa} />
                </td>
                <td style={{ ...tdStyle, fontWeight: 500 }}>{m.name}</td>
                <td style={tdStyle}>
                  {m.cardUid ? (
                    <code style={{
                      fontSize: 12, padding: "3px 8px", borderRadius: 5,
                      background: "rgba(255,255,255,0.05)", color: "rgba(255,255,255,0.55)",
                      fontFamily: "'DM Mono', monospace",
                    }}>{m.cardUid}</code>
                  ) : (
                    <span style={{ color: "rgba(255,255,255,0.2)", fontSize: 12 }}>—</span>
                  )}
                </td>
                <td style={{ ...tdStyle, textAlign: "right", color: "rgba(255,255,255,0.45)" }}>
                  {m.memberSince}
                </td>
                <td style={{ ...tdStyle, textAlign: "right" }}>
                  <button style={actionBtnStyle} title="Bearbeiten">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
                      <path d="M11.5 2.5l2 2L5 13H3v-2l8.5-8.5z" stroke="currentColor" strokeWidth="1.3" strokeLinejoin="round"/>
                    </svg>
                  </button>
                  <button style={{ ...actionBtnStyle, marginLeft: 4 }} title="Löschen">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
                      <path d="M3 5h10M6 5V3.5a.5.5 0 01.5-.5h3a.5.5 0 01.5.5V5m1.5 0l-.5 8a1 1 0 01-1 1H6a1 1 0 01-1-1l-.5-8" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round"/>
                    </svg>
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {filtered.length > 25 && (
          <div style={{
            padding: "12px 20px", textAlign: "center",
            borderTop: "1px solid rgba(255,255,255,0.04)",
            color: "rgba(255,255,255,0.35)", fontSize: 13,
          }}>
            Zeige 25 von {filtered.length} — Seite 1
          </div>
        )}
      </div>
    </div>
  );
}

const thStyle = {
  padding: "11px 16px",
  textAlign: "left",
  fontSize: 11,
  fontWeight: 600,
  textTransform: "uppercase",
  letterSpacing: "0.05em",
  color: "rgba(255,255,255,0.35)",
  whiteSpace: "nowrap",
};

const tdStyle = {
  padding: "10px 16px",
  whiteSpace: "nowrap",
};

const actionBtnStyle = {
  padding: 6, borderRadius: 6, border: "none",
  background: "transparent", color: "rgba(255,255,255,0.3)",
  cursor: "pointer", display: "inline-flex", alignItems: "center",
  transition: "color 0.15s",
};
