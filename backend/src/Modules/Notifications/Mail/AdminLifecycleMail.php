<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A new admin account exists" and "an account's roles moved" (ADR-0044).
 *
 * One class for both, on the precedent {@see EncryptionKeyEventMail} set for
 * its three key events: they are the same message with different words — who
 * the account is, what it can do now, who did it, when — and splitting them
 * would duplicate the layout to vary two strings.
 *
 * ## What it says, and what it deliberately does not
 *
 * It names the **role set the account holds now**, not the delta. The delta is
 * a fact about a moment, and ADR-0038 rule 5 renders from current state at send
 * time — a message queued before a second change and delivered after it would
 * otherwise describe a world that no longer exists. The audit log holds the
 * before and after (`role_granted` / `role_revoked`, migration 045), and the
 * message says so.
 *
 * It carries **no credentials**: not the generated password, not a token, not
 * key material. The password is shown once in the panel and stored nowhere,
 * and this message is addressed more widely than that one screen — every
 * active admin, plus the club-level address.
 *
 * That wider audience is the point (ADR-0044 rule 3). With exactly one admin, a
 * notice about account creation would otherwise travel from the person who
 * acted, to the same person, about something they had just done — and be silent
 * in precisely the case where that one account is the compromised one.
 */
final class AdminLifecycleMail
{
    /**
     * @param list<AdminRole> $roles The account's roles as they stand now.
     * @param string $actorLabel Who acted, or '' when unknown — the row is
     *        dropped rather than printed blank, matching the key and terminal
     *        notices.
     */
    public static function renderAccountCreated(
        string $recipientAddress,
        ?string $recipientName,
        string $accountLabel,
        array $roles,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        return self::render(
            variant: 'created',
            recipientAddress: $recipientAddress,
            recipientName: $recipientName,
            accountLabel: $accountLabel,
            roles: $roles,
            occurredAt: $occurredAt,
            actorLabel: $actorLabel,
            language: $language,
            branding: $branding,
        );
    }

    /** @param list<AdminRole> $roles */
    public static function renderRoleChanged(
        string $recipientAddress,
        ?string $recipientName,
        string $accountLabel,
        array $roles,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        return self::render(
            variant: 'roles',
            recipientAddress: $recipientAddress,
            recipientName: $recipientName,
            accountLabel: $accountLabel,
            roles: $roles,
            occurredAt: $occurredAt,
            actorLabel: $actorLabel,
            language: $language,
            branding: $branding,
        );
    }

    /** @param list<AdminRole> $roles */
    private static function render(
        string $variant,
        string $recipientAddress,
        ?string $recipientName,
        string $accountLabel,
        array $roles,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);
        $subject = $t->t("admin_lifecycle.{$variant}.subject");

        $rows = [
            $t->t('admin_lifecycle.label_account') => $accountLabel,
            $t->t('admin_lifecycle.label_roles') => self::roleList($t, $roles),
            $t->t('admin_lifecycle.label_when') => MailFormat::date($occurredAt, $language),
        ];

        if ($actorLabel !== '') {
            $rows[$t->t('admin_lifecycle.label_actor')] = $actorLabel;
        }

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $t->t("admin_lifecycle.{$variant}.preheader"),
            'lang' => $language->value,
            'content' => self::html($t, $variant, $accountLabel, $recipientName, $rows, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $variant, $accountLabel, $recipientName, $rows, $branding),
            toName: $recipientName,
        );
    }

    /**
     * The roles, in the words the club uses (ADR-0044) rather than the stored
     * identifiers. An account holding none renders as an explicit "(none)"
     * instead of an empty cell, because a blank there reads as a rendering
     * fault rather than as the fact it is.
     *
     * @param list<AdminRole> $roles
     */
    private static function roleList(MailStrings $t, array $roles): string
    {
        if ($roles === []) {
            return $t->t('admin_lifecycle.roles_none');
        }

        return implode(', ', array_map(
            static fn (AdminRole $role): string => $t->t('admin_lifecycle.role.' . $role->value),
            $roles,
        ));
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        string $variant,
        string $accountLabel,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('admin_lifecycle.eyebrow'))
            . MailLayout::title($t->t("admin_lifecycle.{$variant}.title"))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $recipientName)))
            . MailLayout::lede($t->t("admin_lifecycle.{$variant}.lede", [
                'account' => MailLayout::esc($accountLabel),
            ]))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_lifecycle.expected')))
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_lifecycle.unexpected')))
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_lifecycle.no_secret')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        string $variant,
        string $accountLabel,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t("admin_lifecycle.{$variant}.title"),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $recipientName),
            '',
            $t->t("admin_lifecycle.{$variant}.lede_text", ['account' => $accountLabel]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t('admin_lifecycle.expected');
        $out[] = '';
        $out[] = $t->t('admin_lifecycle.unexpected');
        $out[] = '';
        $out[] = $t->t('admin_lifecycle.no_secret');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
