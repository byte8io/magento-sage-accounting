import type { SidebarsConfig } from '@docusaurus/plugin-content-docs';

const sidebars: SidebarsConfig = {
  docsSidebar: [
    'intro',
    {
      type: 'category',
      label: 'Getting started',
      collapsed: false,
      items: [
        'getting-started/quick-start',
        'getting-started/installation',
        'getting-started/first-sync',
      ],
    },
    {
      type: 'category',
      label: 'Connect',
      items: [
        'connect/pairing-code',
        'connect/sage-oauth',
        'connect/disconnect',
      ],
    },
    {
      type: 'category',
      label: 'Sync settings',
      items: [
        'settings/sync-behavior',
        'settings/default-mappings',
        'settings/payment-methods',
        'settings/tax-rates',
        'settings/commercial',
        'settings/multi-currency',
        'settings/b2c-consolidation',
      ],
    },
    {
      type: 'category',
      label: 'Magento admin',
      items: [
        'magento-admin/sage-status-grid',
        'magento-admin/sage-status-detail',
        'magento-admin/dead-letter-banner',
      ],
    },
    'what-syncs',
    'troubleshooting',
    'faq',
  ],
};

export default sidebars;
