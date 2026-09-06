<?php

namespace GlpiPlugin\Glpimobile;

use Config;
use GlpiPlugin\Glpisignal\Channel\ChannelInterface;
use GlpiPlugin\Glpisignal\Channel\PageOutcome;
use GlpiPlugin\Glpisignal\Channel\PageRequest;

/**
 * Mobile push as a glpi-signal paging channel.
 *
 * Registered on the `glpisignal_channels` hook — and only when glpi-signal is
 * active (see setup.php), so this plugin keeps working standalone: this file
 * references glpi-signal classes and is only ever autoloaded when glpi-signal
 * asks its channel registry for contributions.
 *
 * A page becomes a queued push deep-linking to `/alerts/<id>` in the app.
 * Always the alert route, never the bound ticket: the alert screen is where
 * ack/escalation context lives, and the `route` field ships in the same app
 * release as the alerts UI itself, so there is no older-app audience for a
 * ticket-only fallback. The push is *queued* here and delivered by this
 * plugin's own cron — same semantics as glpi-signal's email channel, which
 * also hands off to a queue.
 */
final class SignalChannel implements ChannelInterface
{
    /**
     * Hook callback for `$PLUGIN_HOOKS['glpisignal_channels']['glpimobile']`.
     *
     * @return ChannelInterface[]
     */
    public static function all(): array
    {
        return [new self()];
    }

    public function id(): string
    {
        return 'glpimobile_push';
    }

    public function label(): string
    {
        return __('Mobile push (GLPI Mobile)', 'glpimobile');
    }

    /**
     * Honest availability: at least one transport must be able to deliver.
     * VAPID keys are auto-generated at install, so this is normally true;
     * false means the plugin's push config is genuinely broken.
     */
    public function available(): bool
    {
        $vapid = Config::getConfigurationValue(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            'vapid_public_key'
        );
        return !empty($vapid) || Fcm::isConfigured() || Apns::isConfigured();
    }

    public function unavailableReason(): string
    {
        return $this->available()
            ? ''
            : __('No push transport is configured (missing VAPID keys, and neither FCM nor APNs is set up).', 'glpimobile');
    }

    public function send(PageRequest $request): PageOutcome
    {
        $target = $request->user_name !== ''
            ? $request->user_name
            : sprintf('user #%d', $request->users_id);

        if (!$this->available()) {
            return PageOutcome::skipped($target, $this->unavailableReason());
        }

        try {
            $queued = Push::enqueueRoute(
                $request->users_id,
                $request->subject,
                $request->body,
                '/alerts/' . $request->alerts_id
            );
        } catch (\Throwable $e) {
            // The contract says never throw: an escaping exception would
            // abandon every later escalation step in the cron.
            return PageOutcome::failed($target, mb_substr($e->getMessage(), 0, 400));
        }

        if (!$queued) {
            return PageOutcome::skipped(
                $target,
                __('that user has no registered mobile device', 'glpimobile')
            );
        }

        return PageOutcome::sent($target, __('queued for mobile push delivery', 'glpimobile'));
    }
}
