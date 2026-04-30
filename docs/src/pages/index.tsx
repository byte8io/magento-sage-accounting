import Link from '@docusaurus/Link';
import Layout from '@theme/Layout';
import styles from './index.module.css';

interface FeatureCardProps {
  href: string;
  icon: string;
  title: string;
  body: string;
  cta: string;
}

function FeatureCard({ href, icon, title, body, cta }: FeatureCardProps) {
  return (
    <Link to={href} className={styles.featureCard}>
      <span className={styles.featureIcon} aria-hidden>{icon}</span>
      <h3 className={styles.featureTitle}>{title}</h3>
      <p className={styles.featureBody}>{body}</p>
      <span className={styles.featureFooter}>{cta} ↘</span>
    </Link>
  );
}

export default function Home(): React.ReactElement {
  return (
    <Layout
      title="Byte8 Sage Accounting — Magento 2 to Sage Business Cloud"
      description="Hosted SaaS connector between Magento 2 and Sage Business Cloud Accounting. Invoices, credit notes, customers, products, payments. Multi-currency. Audited. Hands-off."
    >
      <main>
        {/* Hero */}
        <section className={styles.heroSection}>
          <div className={styles.heroContent}>
            <span className={styles.eyebrow}>Magento 2 · Sage Business Cloud · Hosted SaaS</span>
            <h1 className={styles.heroTitle}>
              Magento 2 → Sage Accounting.{' '}
              <span className={styles.heroTitleAccent}>Hands-off.</span>
            </h1>
            <p className={styles.heroSubtitle}>
              Invoices, credit notes, customers, products, payments — synced
              from Magento into Sage Business Cloud Accounting within minutes.
              Multi-currency aware. Per-currency contact dedup. Cross-border
              tax routing. Full audit trail per sync run. We host the
              connector — you install a thin Magento module and forget about it.
            </p>
            <div className={styles.heroCtas}>
              <Link className="button button--primary button--lg" to="/docs/getting-started/quick-start">
                Quick start
              </Link>
              <Link className="button button--secondary button--lg" to="/docs/">
                Read the docs
              </Link>
            </div>

            <div className={styles.statsRow}>
              <div className={styles.stat}>
                <span className={styles.statValue}>&lt; 60s</span>
                <span className={styles.statLabel}>Cron drain interval</span>
              </div>
              <div className={styles.stat}>
                <span className={styles.statValue}>5</span>
                <span className={styles.statLabel}>Magento entities synced</span>
              </div>
              <div className={styles.stat}>
                <span className={styles.statValue}>0 OAuth</span>
                <span className={styles.statLabel}>You handle in Magento</span>
              </div>
              <div className={styles.stat}>
                <span className={styles.statValue}>SaaS</span>
                <span className={styles.statLabel}>Centrally patched</span>
              </div>
            </div>
          </div>
        </section>

        {/* Core capabilities */}
        <section className={styles.section}>
          <header className={styles.sectionHeader}>
            <span className={styles.sectionEyebrow}>Core sync</span>
            <p className={styles.sectionLead}>
              Five Magento entity events flow into Sage automatically — with
              full idempotency, retry, and audit on every step.
            </p>
          </header>

          <div className={styles.cardGrid}>
            <FeatureCard
              href="/docs/what-syncs"
              icon="🧾"
              title="Invoices + payments"
              body="Magento invoices land in Sage as outstanding AR the moment they're raised. invoice.paid attaches a contact_payment + allocation against the matching Sage invoice automatically. B2B net-terms flows leave invoices UNPAID for manual reconciliation."
              cta="What syncs"
            />
            <FeatureCard
              href="/docs/what-syncs"
              icon="↩️"
              title="Credit notes"
              body="Magento credit memos sync as Sage credit notes with original-invoice linkage. Offline-payment refunds (no parent invoice) handled. Same per-line discount + per-line tax invariants as invoices."
              cta="Credit memos"
            />
            <FeatureCard
              href="/docs/settings/multi-currency"
              icon="💱"
              title="Multi-currency aware"
              body="Magento orders raised against EUR / USD / GBP storefronts post to Sage with the correct currency_id + exchange_rate. Per-currency contact dedup means one merchant gets one Sage contact per transaction currency. Cross-border invoices route to GB_ZERO."
              cta="Multi-currency"
            />
          </div>
        </section>

        {/* Visibility */}
        <section className={styles.section}>
          <header className={styles.sectionHeader}>
            <span className={styles.sectionEyebrow}>Magento admin visibility</span>
            <p className={styles.sectionLead}>
              See sync status without leaving the Magento admin you already
              live in.
            </p>
          </header>

          <div className={styles.cardGrid}>
            <FeatureCard
              href="/docs/magento-admin/sage-status-grid"
              icon="🟢"
              title="Sage Status chip on grids"
              body="Sortable + filterable Sage Status column on Sales → Invoices and Sales → Credit Memos. Pending / Synced / Skipped / Failed chips with hover tooltips for the underlying Sage reference, skip-reason, or error code."
              cta="Status grid"
            />
            <FeatureCard
              href="/docs/magento-admin/sage-status-detail"
              icon="📒"
              title="Detail-page info block"
              body="Every Invoice and Credit Memo detail page gets a Sage Accounting info block beside Order Information — chip, Sage entity reference, last sync timestamp, skip / error context."
              cta="Detail block"
            />
            <FeatureCard
              href="/docs/magento-admin/dead-letter-banner"
              icon="🚨"
              title="Dead-letter banner"
              body="Failed deliveries surface as a banner on the admin config page — operator-visible without log diving. Per-row retry from the ledger dashboard re-enters the queue cleanly."
              cta="Dead-letter handling"
            />
          </div>
        </section>

        {/* SaaS chassis */}
        <section className={styles.section}>
          <header className={styles.sectionHeader}>
            <span className={styles.sectionEyebrow}>SaaS chassis</span>
            <p className={styles.sectionLead}>
              The Magento module is thin by design. The heavy lifting lives in
              the hosted ledger — so you never patch your connector.
            </p>
          </header>

          <div className={styles.cardGrid}>
            <FeatureCard
              href="/docs/connect/sage-oauth"
              icon="🔐"
              title="No OAuth in PHP"
              body="Sage OAuth lives entirely in our hosted ledger SaaS. Magento never talks to api.accounting.sage.com directly — no client secret on disk, no token rotation logic on your server, no breaking-API patches to ship."
              cta="OAuth flow"
            />
            <FeatureCard
              href="/docs/connect/pairing-code"
              icon="🤝"
              title="Pairing-code Connect"
              body="Generate a 30-min pairing code in your Magento admin, paste it into ledger.byte8.io, and the chassis handshakes back in. No callback URL wrangling, no app secret on disk."
              cta="Connect flow"
            />
            <FeatureCard
              href="/docs/troubleshooting"
              icon="🔁"
              title="Centrally-patched"
              body="Sage API breaks? We patch the chassis and every connected merchant gets the fix. Eight Sage v3.1 quirks already catalogued and worked around — your invoice flow stays green through API drift."
              cta="Troubleshooting"
            />
          </div>
        </section>

        {/* CTA band */}
        <section className={styles.ctaBand}>
          <h2 className={styles.ctaTitle}>60 seconds to live sync.</h2>
          <p className={styles.ctaSubtitle}>
            <code>composer require byte8/magento-sage-accounting</code> · run setup:upgrade · pair with ledger.byte8.io.
          </p>
          <div className={styles.heroCtas}>
            <Link className="button button--primary button--lg" to="/docs/getting-started/quick-start">
              Quick start
            </Link>
            <Link className="button button--secondary button--lg" to="https://byte8.io/products/sage-accounting">
              Plans & pricing
            </Link>
          </div>
        </section>
      </main>
    </Layout>
  );
}
