<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Facades\WorkOS;

class AdminPortalLinks extends Component
{
    public ?Organization $organization = null;

    public array $intents = [
        'sso' => [
            'label' => 'Single Sign-On',
            'description' => 'Configure SAML or OIDC identity provider',
            'icon' => 'key',
        ],
        'dsync' => [
            'label' => 'Directory Sync',
            'description' => 'Sync users and groups from your directory',
            'icon' => 'users',
        ],
        'audit_logs' => [
            'label' => 'Audit Logs',
            'description' => 'View security and activity logs',
            'icon' => 'document-text',
        ],
        'log_streams' => [
            'label' => 'Log Streams',
            'description' => 'Export logs to your SIEM',
            'icon' => 'arrow-trending-up',
        ],
        'domain_verification' => [
            'label' => 'Domain Verification',
            'description' => 'Verify ownership of your domain',
            'icon' => 'shield-check',
        ],
        'certificate_renewal' => [
            'label' => 'Certificate Renewal',
            'description' => 'Renew SAML certificates',
            'icon' => 'document-check',
        ],
    ];

    public function getPortalLink(string $intent): ?string
    {
        if (! $this->organization) {
            return null;
        }

        try {
            /** @var \WorkOS\Portal $portal */
            $portal = WorkOS::portal();

            $link = $portal->generateLink(
                organization: $this->organization->workos_id,
                intent: $intent,
                returnUrl: route('organizations.settings'),
                successUrl: route('organizations.settings'),
            );

            return $link->link;
        } catch (\Exception $e) {
            report($e);

            return null;
        }
    }

    public function render(): View
    {
        $links = [];
        if ($this->organization) {
            foreach ($this->intents as $intent => $config) {
                $links[$intent] = $this->getPortalLink($intent);
            }
        }

        return view('livewire.admin-portal-links', [
            'links' => $links,
        ]);
    }
}
