-- =============================================================================
-- 046_mail_config_club_notification_address.sql — the alarm survives one admin
-- =============================================================================
-- ADR-0044 rule 3. ADR-0043 already noted, honestly, that a single-admin
-- installation gets no security benefit from admin-addressed alert mail, only a
-- receipt. For admin *lifecycle* mail the problem is sharper: with exactly one
-- admin, a message about an account being created goes from the actor, to the
-- actor, about a thing they just did — and it is silent in precisely the case
-- that matters, where that one account is the compromised one.
--
-- This column is the second pair of eyes: a club-level address — a Vorstand
-- list, the Kassenwart's inbox, whatever the club actually reads — that
-- receives admin lifecycle mail **in addition to** every active admin account,
-- not instead of them.
--
-- NULL means unset, and unset means today's behaviour exactly: active admins
-- only, no error, nothing queued to nowhere. An installation that never
-- configures this is not broken, it simply has not bought the property.
--
-- It lives on `mail_config` because that is the table the panel's mail settings
-- already write, and `mail-config` is `admin`-only in ADR-0044's grant table.
-- That placement is load-bearing rather than convenient: **a Kassenwart cannot
-- redirect the alert channel to themselves**, and every other detection in this
-- epic depends on that. Put this field anywhere a lesser role can write and the
-- containment story in #513 becomes false.
--
-- VARCHAR(255) to match `sender_address` and `reply_to_address` beside it —
-- one address, not a list. A club that wants several uses the thing built for
-- the job: a mailing list address.
--
-- Rollback: db/rollback/046_mail_config_club_notification_address.down.sql
-- =============================================================================

ALTER TABLE mail_config
    ADD COLUMN club_notification_address VARCHAR(255) NULL
        COMMENT 'Club-level address that also receives admin lifecycle mail (ADR-0044 rule 3)'
        AFTER reply_to_address;
