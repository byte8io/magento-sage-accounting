import { themes as prismThemes } from 'prism-react-renderer';
import type { Config } from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

const config: Config = {
  title: 'Byte8 Sage Accounting',
  tagline:
    'Magento 2 → Sage Business Cloud Accounting. Hosted SaaS connector — invoices, credit notes, customers, products, payments. Multi-currency. Audited. Hands-off.',
  favicon: 'img/favicon.svg',

  future: {
    v4: true,
  },

  // Production URL — served under unified docs domain (Cloudflare Pages + Worker router).
  // See apps/docs-router in the byte8.io monorepo + docs/DOCS_SITE_MIGRATION.md.
  url: 'https://docs.byte8.io',
  baseUrl: '/sage/',
  trailingSlash: false,

  onBrokenLinks: 'warn',

  markdown: {
    hooks: {
      onBrokenMarkdownLinks: 'warn',
    },
  },

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      {
        docs: {
          sidebarPath: './sidebars.ts',
          routeBasePath: 'docs',
          editUrl:
            'https://github.com/byte8io/magento-sage-accounting/edit/main/docs/',
        },
        blog: {
          showReadingTime: true,
          blogTitle: 'Changelog & updates',
          blogDescription: 'Release notes for Byte8 Sage Accounting',
          postsPerPage: 10,
          feedOptions: {
            type: ['rss', 'atom'],
            xslt: true,
          },
          editUrl:
            'https://github.com/byte8io/magento-sage-accounting/edit/main/docs/',
        },
        theme: {
          customCss: './src/css/custom.css',
        },
      } satisfies Preset.Options,
    ],
  ],

  themeConfig: {
    image: 'img/social-card.png',
    colorMode: {
      defaultMode: 'dark',
      disableSwitch: false,
      respectPrefersColorScheme: false,
    },
    navbar: {
      title: 'Byte8',
      logo: {
        alt: 'Byte8 Sage Accounting',
        src: 'img/logo.svg',
        srcDark: 'img/logo.svg',
        width: 32,
        height: 32,
      },
      items: [
        {
          type: 'docSidebar',
          sidebarId: 'docsSidebar',
          position: 'left',
          label: 'Docs',
        },
        { to: '/blog', label: 'Changelog', position: 'left' },
        {
          href: 'https://byte8.io/products/sage-accounting#pricing',
          label: 'Pricing',
          position: 'left',
        },
        {
          href: 'https://github.com/byte8io/magento-sage-accounting',
          position: 'right',
          className: 'header-github-link',
          'aria-label': 'GitHub repository',
        },
        {
          href: 'https://byte8.io/products/sage-accounting',
          label: 'Start Free Trial',
          position: 'right',
          className: 'navbar-cta-button',
        },
      ],
    },
    footer: {
      style: 'dark',
      logo: {
        alt: 'Byte8',
        src: 'img/logo.svg',
        href: 'https://byte8.io',
        width: 32,
        height: 32,
      },
      links: [
        {
          title: 'Docs',
          items: [
            { label: 'Quick start', to: '/docs/getting-started/quick-start' },
            { label: 'Connect flow', to: '/docs/connect/pairing-code' },
            { label: 'Sync settings', to: '/docs/settings/sync-behavior' },
            { label: 'What syncs', to: '/docs/what-syncs' },
            { label: 'Troubleshooting', to: '/docs/troubleshooting' },
          ],
        },
        {
          title: 'Resources',
          items: [
            { label: 'Changelog', to: '/blog' },
            { label: 'Pricing', href: 'https://byte8.io/products/sage-accounting#pricing' },
            { label: 'GitHub', href: 'https://github.com/byte8io/magento-sage-accounting' },
            { label: 'Sage Marketplace', href: 'https://marketplace.sage.com/' },
          ],
        },
        {
          title: 'Byte8',
          items: [
            { label: 'byte8.io', href: 'https://byte8.io' },
            { label: 'Sage Accounting product', href: 'https://byte8.io/products/sage-accounting' },
            { label: 'Stock Radar', href: 'https://magento-stock-radar.byte8.dev' },
            { label: 'Contact', href: 'mailto:helo@byte8.io' },
          ],
        },
      ],
      copyright: `© ${new Date().getFullYear()} Byte8 Ltd. MIT licensed.`,
    },
    prism: {
      theme: prismThemes.vsDark,
      darkTheme: prismThemes.vsDark,
      additionalLanguages: ['php', 'bash', 'json', 'xml-doc', 'tsx', 'sql', 'graphql'],
    },
    // Algolia DocSearch — cross-product search across every docs.byte8.io/* site.
    // Public Search-Only credentials (designed to ship in client JS, restricted to
    // read-only queries on this index). Safe to commit.
    algolia: {
      appId: 'VWO679B1LI',
      apiKey: 'b3f3b5b76b0d684e50796c9e045b41e5',
      indexName: 'Byte8 Documentation Site',
      contextualSearch: false,
      searchPagePath: 'search',
    },
  } satisfies Preset.ThemeConfig,
};

export default config;
