# MASTER PROMPT: International-Standard E-commerce Platform Architecture & AI-Assisted Development Roadmap

## 1. Your Role and Responsibilities

Act as a world-class team of specialists consisting of:

- Chief Software Architect
- Senior Laravel Application Architect
- E-commerce Domain-Driven Design (DDD) Expert
- PostgreSQL Database Architect
- Bangladeshi E-commerce Business Consultant
- API and Mobile Application Architect
- UI/UX and Accessibility Specialist
- Cybersecurity and Application Security Engineer
- DevOps, QA, and Automation Engineer
- AI Coding Agent Orchestrator and Technical Project Manager

Your task is to analyze, design, and document a complete, production-ready architecture for an international-standard e-commerce application tailored to the Bangladeshi market.

The platform must support different categories of products and services, initially offer English and Bangla, and provide a secure, scalable backend that can serve both a web application and future Android and iOS mobile applications.

Do not start coding immediately. First establish the business requirements, architecture, domain model, implementation standards, security controls, and phased AI-assisted development roadmap.

Think like an architect building a serious commercial product rather than a basic online shop or tutorial application.

---

## 2. Project Overview

### Business objective

Build a professional, extensible e-commerce platform for the Bangladesh market, with an architecture capable of supporting international expansion in the future.

### Initial technology stack

- Backend: Laravel, using the latest stable version compatible with the selected dependencies
- Web frontend: Laravel Blade
- Database: PostgreSQL
- Styling: Tailwind CSS or another justified, maintainable frontend styling solution
- Frontend interactivity: Alpine.js or another lightweight solution where appropriate
- Authentication: Laravel-compatible authentication with secure session management
- API: Versioned REST API for future mobile applications and approved external integrations
- Queue processing: Laravel Queues with a suitable production queue backend
- Cache: Redis where justified
- File and image storage: S3-compatible object storage or an appropriate local development abstraction
- Testing: Pest or PHPUnit, with browser and API testing
- Deployment: Linux-based production hosting, automated deployment, and CI/CD

Treat this as a proposed stack, not an inflexible checklist. Verify framework and dependency compatibility, explain important choices, and document alternatives where useful.

Keep the primary customer-facing web application based on Laravel Blade. Do not introduce a separate SPA framework unless a clearly justified business requirement demands it.

### Languages

The initial release must support:

1. English (en)
2. Bangla (bn)

The architecture must support adding further languages without restructuring the application.

Requirements include:

- Localized product names and descriptions
- Localized category and service content
- Translation of navigation, validation messages, notifications, and transactional emails
- Language selection and persistence
- Search behavior appropriate for Bangla and English
- Bangla-compatible fonts and typography
- Correct rendering of Bengali characters throughout the application
- Appropriate locale-aware formatting for dates, numbers, and prices

Use BDT (৳) as the default currency, with a configurable currency architecture for possible future expansion.

---

## 3. Business Model and Catalog Requirements

Design a unified commerce platform that can sell multiple types of products and services.

### A. Physical products

Examples include electronics, clothing, groceries, home appliances, books, accessories, and other retail products.

Support:

- Product variants such as size, color, and model
- SKU and barcode management
- Product attributes and specifications
- Product images and galleries
- Product categories, brands, tags, and collections
- Inventory and stock availability
- Warehouses and stock locations
- Product pricing, promotional pricing, and scheduled sales
- Product bundles and kits where applicable
- Product reviews and ratings
- Related, recommended, and recently viewed products
- Shipping dimensions and weight
- Returns, refunds, exchanges, and warranty information

### B. Digital products

Examples include downloadable documents, e-books, software licenses, templates, and other digital goods.

Support:

- Secure digital delivery
- Access authorization after payment
- Download limits and expiry policies where required
- License keys or entitlement management when applicable
- Refund restrictions and clear customer-facing policies
- Protected storage and download audit trails

### C. Services

Examples include consulting, repair services, professional services, bookings, subscriptions, and other service-based offerings.

Support relevant capabilities such as:

- Service descriptions and packages
- Service pricing and configurable fees
- Appointment or booking scheduling
- Availability calendars
- Service locations and service areas
- Booking confirmation and cancellation
- Customer requirements and attachments
- Service fulfillment status
- Completion confirmation
- Refund and cancellation policies

Do not force all services into a physical-product inventory model. Establish an appropriate domain model for service fulfillment.

### D. Future catalog extensions

Evaluate whether the initial architecture should accommodate:

- Subscription products and recurring billing
- Gift cards and vouchers
- Pre-orders and backorders
- Wholesale and B2B pricing
- Multi-vendor marketplaces
- Vendor commissions and settlements
- Seller-managed inventory
- Auctions or other specialized sales models

Classify each capability as MVP, subsequent phase, or future extension. Avoid building expensive functionality before there is a confirmed business need.

---

## 4. Architecture and Engineering Principles

Design the platform using the following principles:

1. Modular monolith architecture for the initial release.
2. Domain-driven design where it provides meaningful boundaries.
3. Clear separation of controllers, application services, domain logic, infrastructure, and presentation.
4. Thin controllers and reusable business services.
5. Laravel conventions and framework-native capabilities wherever practical.
6. PostgreSQL as the authoritative transactional database.
7. API-first business logic that supports Blade and mobile clients without duplicating core rules.
8. Secure, transactional order and payment processing.
9. Database integrity enforced through appropriate constraints, foreign keys, and transactions.
10. Testable, maintainable, and observable code.
11. Accessibility, performance, localization, and security built into the architecture.
12. Avoid unnecessary abstractions, excessive repository patterns, and premature microservices.

Explain which modules can operate independently and which require synchronous or asynchronous communication.

The application must be deployable as a modular monolith without requiring a microservices infrastructure.

---

## 5. Required Functional Modules

Evaluate and document the complete module structure.

### Customer-facing modules

- Home page and promotional landing pages
- Product and service catalog
- Categories, brands, attributes, and filters
- Product and service details
- Search and autocomplete
- Shopping cart
- Guest checkout
- Customer registration and authentication
- Customer profile and address book
- Checkout and order placement
- Payment selection
- Delivery and shipping selection
- Order tracking
- Order history and reorder
- Digital product library
- Service booking management
- Wishlist and product comparison where justified
- Product reviews and ratings
- Coupons, discounts, and promotional campaigns
- Customer support and contact requests
- Return, exchange, cancellation, and refund requests
- Customer notifications
- Static pages, FAQs, policies, and help center

### Administration modules

- Admin dashboard
- User and staff management
- Roles and permissions
- Product catalog management
- Product variants and attributes
- Category and brand management
- Digital product management
- Service and booking management
- Inventory and warehouse management
- Purchase and supplier management, if required
- Order management
- Payment and refund management
- Shipping and delivery management
- Courier integration management
- Promotions and coupon management
- Customer management
- Review moderation
- CMS and multilingual content management
- Reports and analytics
- Configuration and business settings
- Notification templates
- Audit logs
- Integration settings
- Background job monitoring
- Import and export tools

### Commerce and operational modules

- Pricing engine
- Tax and fee calculation
- Inventory reservation
- Order lifecycle management
- Payment lifecycle management
- Fulfillment orchestration
- Shipping rate calculation
- Discount validation
- Refund processing
- Digital entitlement delivery
- Service booking allocation
- Reconciliation and exception handling

Define module boundaries, dependencies, ownership of business rules, and cross-module integration mechanisms.

---

## 6. Bangladeshi Market Requirements

Research and design for the actual operating environment of Bangladesh.

Consider:

### Payments

Evaluate suitable integrations for:

- SSLCommerz
- ShurjoPay
- aamarPay
- bKash
- Nagad
- Cards and bank payments
- Cash on Delivery (COD)
- Bank transfer, where appropriate

Verify current API documentation, onboarding requirements, supported payment methods, and commercial limitations before recommending a production integration.

Do not assume that every gateway supports every payment method or refund workflow.

Design a provider-independent payment interface so that gateways can be added or replaced without rewriting checkout and order management.

Include:

- Payment initiation
- Server-to-server callback verification
- Webhook signature or authenticity validation
- Idempotent payment processing
- Duplicate callback protection
- Pending, successful, failed, cancelled, and refunded states
- Reconciliation
- Payment expiry and retry handling
- Secure handling of payment references
- COD collection and reconciliation
- Protection against forged payment-success redirects

Never mark an order as paid merely because a customer returns to a success URL.

### Delivery and logistics

Evaluate integrations with relevant Bangladesh courier and logistics providers, such as:

- Pathao Courier
- Steadfast Courier
- RedX
- Paperfly
- Other appropriate providers based on verified availability

Support:

- Dhaka and outside-Dhaka delivery
- District and upazila-based delivery zones
- Configurable shipping rates
- Free-shipping rules
- Cash-on-delivery fees
- Shipment creation and tracking
- Delivery status synchronization
- Partial fulfillment where applicable
- Failed delivery and return-to-origin workflows
- Courier reconciliation

Build a provider-independent shipping integration layer.

### Localization and customer experience

Support:

- Bangladeshi mobile number validation
- Appropriate international phone-number normalization
- District, upazila, area, and address collection
- English and Bangla product content
- BDT currency formatting
- Clear COD and delivery information
- Mobile-friendly checkout
- Low-bandwidth performance
- Accessible interfaces and readable typography
- Customer support channels relevant to the target market

### Legal and regulatory requirements

Identify the relevant Bangladesh laws, regulations, tax requirements, consumer-protection obligations, digital-commerce requirements, privacy obligations, and payment-provider rules that may apply.

Distinguish verified legal requirements from recommendations and assumptions. Cite current authoritative sources and identify matters requiring review by a qualified Bangladesh legal or tax professional.

Do not invent compliance certifications or claim that the platform is legally compliant merely because recommended controls have been implemented.

---

## 7. PostgreSQL Database Architecture

Design a normalized and maintainable PostgreSQL schema.

Provide:

- Logical data model
- Entity relationship diagram using Mermaid
- Table-by-table data dictionary
- Primary and foreign keys
- Unique constraints
- Check constraints
- Indexing strategy
- Transaction boundaries
- Soft-delete policy
- Audit and timestamp conventions
- Data retention and archival strategy
- Migration ordering
- Seed-data strategy

At minimum, evaluate entities for:

- Users and customer profiles
- Addresses
- Roles and permissions
- Products and product variants
- Product translations
- Categories and localized categories
- Brands
- Attributes and attribute values
- Product images and media
- Prices and promotions
- Inventory items and stock locations
- Inventory movements
- Stock reservations
- Suppliers and purchase orders, if applicable
- Carts and cart items
- Orders and order items
- Order status history
- Payments and payment transactions
- Refunds
- Shipments and shipment events
- Coupons and discount rules
- Reviews and ratings
- Service definitions
- Service availability and bookings
- Digital assets and entitlements
- Notifications
- CMS pages and translations
- Audit events
- Webhook events
- Idempotency records
- Integration configurations

Evaluate product-type modeling carefully. Explain how physical products, digital goods, and services can share common catalog capabilities while retaining their own fulfillment rules.

For localized data, compare normalized translation tables with JSONB-based approaches and justify the chosen approach.

Use PostgreSQL features appropriately, including:

- Foreign keys and transactional integrity
- Partial indexes when useful
- JSONB only for genuinely flexible attributes or provider-specific metadata
- Full-text search and trigram search where suitable
- Appropriate numeric precision for monetary values
- Safe handling of concurrent stock changes
- Appropriate isolation and locking for critical operations

Document inventory and payment concurrency strategies, including race conditions and prevention of overselling.

Include example Laravel migrations for the foundational entities after the architecture is approved.

---

## 8. Laravel Application Structure

Propose a concrete folder structure compatible with the selected Laravel version.

Organize modules around business capabilities, such as:

- Identity and Access
- Catalog
- Pricing and Promotions
- Cart
- Checkout
- Orders
- Payments
- Inventory
- Fulfillment and Shipping
- Digital Products
- Services and Bookings
- Customers
- Notifications
- CMS
- Reporting
- Integrations
- Platform Administration

For each module, define:

- Responsibilities
- Models and relationships
- Controllers
- Form Requests and validation
- Application services or actions
- Policies and authorization
- Events and listeners
- Jobs and scheduled tasks
- API resources
- Blade views
- Tests
- Database migrations

Use appropriate Laravel capabilities, such as queues, events, notifications, policies, rate limiting, caching, validation, and transactions.

Recommend a consistent naming convention and dependency direction.

Provide example request-to-database and request-to-payment flows.

---

## 9. Web UI/UX Architecture

Design a professional, responsive e-commerce experience.

### Customer storefront

Include:

- Responsive desktop, tablet, and mobile layouts
- English/Bangla language switcher
- Search-first navigation
- Category navigation
- Product cards and detail pages
- Filterable product listing
- Sorting and pagination
- Shopping cart drawer or page
- Multi-step or streamlined checkout
- Clear order confirmation
- Customer account area
- Service booking flow
- Accessible forms and error states

### Administration panel

Use a professional administration layout with:

- Sidebar navigation
- Dashboard metrics
- Filterable and paginated tables
- Search and bulk actions
- Data validation
- Status indicators
- Order-processing workflows
- Inventory alerts
- Payment and refund status
- Export functionality with authorization
- Audit-friendly action history

Use Blade components and reusable layouts to avoid duplicated templates.

Provide wireframe descriptions for major screens and define design tokens, typography, spacing, colors, buttons, forms, tables, and responsive behavior.

Prioritize usability, performance, accessibility, and maintainability over unnecessary visual complexity.

---

## 10. API and Mobile Application Readiness

The platform must support a future native Android and iOS application.

Design a versioned REST API, such as `/api/v1`, using the same underlying business services as the web application.

Document:

- Authentication and token lifecycle
- Customer profile and addresses
- Catalog and search
- Cart management
- Checkout
- Orders and tracking
- Payments
- Digital entitlements
- Service bookings
- Notifications
- Wishlist and reviews where applicable

Define:

- Request and response conventions
- Resource serialization
- Validation errors
- Pagination and filtering
- Consistent error codes
- API versioning
- Rate limiting
- Idempotency for critical write operations
- Authorization and ownership checks
- OpenAPI documentation
- Deprecation policy

Use Laravel Sanctum or another justified Laravel-compatible authentication solution, considering the differences between first-party web sessions and native mobile clients.

Do not expose internal database structures directly through API responses.

Explain how mobile clients can be developed independently without duplicating business rules.

---

## 11. Security and Trust

Adopt a threat-model-driven security approach.

Address:

- OWASP application security risks
- Authentication and session security
- Authorization and privilege escalation
- Password hashing and account recovery
- MFA for privileged administrators where appropriate
- CSRF, XSS, SQL injection, and SSRF protection
- Secure file uploads and downloads
- Payment callback validation
- API authentication and rate limiting
- Secrets management
- Encryption in transit and appropriate encryption at rest
- Audit logs for sensitive administrative actions
- Personally identifiable information protection
- Data retention and deletion
- Backup encryption and restoration testing
- Dependency vulnerability management
- Security monitoring and incident response
- Fraud prevention and suspicious-order controls

Never store raw card details, CVV values, or payment-provider secrets in ordinary application records.

Include a practical security checklist for development, staging, and production.

---

## 12. Performance, Reliability, and Scalability

Define measurable performance and reliability targets instead of vague statements such as "high performance."

Consider:

- Database indexing and query optimization
- N+1 query prevention
- Pagination and filtering
- Redis caching where appropriate
- Queue processing
- CDN and image optimization
- Responsive image delivery
- Search indexing strategy
- Rate limiting
- Database connection management
- Queue retry policies
- Failed-job handling
- Monitoring and alerting
- Application and database backups
- Recovery Time Objective (RTO)
- Recovery Point Objective (RPO)
- Horizontal application scaling
- Scheduled maintenance
- Graceful degradation when external integrations fail

Identify likely bottlenecks and establish a performance-testing plan.

Do not introduce Elasticsearch, Kafka, Kubernetes, or microservices by default. Recommend additional infrastructure only when justified by measured requirements.

---

## 13. Reports and Business Analytics

Define operational and management reporting requirements, including:

- Sales summary
- Sales by date, product, category, and channel
- Revenue and order trends
- Product profitability where cost data is available
- Payment-method analysis
- COD collection and reconciliation
- Pending and failed payments
- Refunds and cancellations
- Delivery performance
- Inventory availability and aging
- Low-stock and out-of-stock reports
- Best-selling products
- Customer purchase history
- Repeat-purchase rates
- Service bookings and completion
- Promotional campaign performance

Clearly distinguish gross sales, discounts, collected payments, refunds, taxes, delivery charges, and recognized revenue.

Apply appropriate access controls to financial reports and customer data.

---

## 14. Testing and Quality Assurance

Define a testing strategy covering:

- Unit tests
- Feature tests
- API tests
- Database integrity tests
- Browser and end-to-end tests
- Payment integration tests
- Queue and event tests
- Authorization tests
- Localization tests
- Inventory concurrency tests
- Regression tests
- Accessibility tests
- Performance and load tests
- Security tests

Include high-risk scenarios such as:

1. Two customers purchasing the final unit simultaneously.
2. Duplicate payment callbacks.
3. A successful payment followed by an application timeout.
4. A failed payment followed by a retry.
5. An order cancellation racing with fulfillment.
6. A refund requested for an already-refunded amount.
7. A coupon being redeemed beyond its limit.
8. Unauthorized access to another customer's order.
9. A digital product download without a valid entitlement.
10. A service booking conflict for the same time slot.

Require automated tests to pass before a phase can be considered complete.

Use test payment gateways or sandbox environments during development. Never test live payment flows with real customer funds without explicit authorization and appropriate safeguards.

---

## 15. AI-Assisted Development Strategy

Design the application for implementation using AI coding agents such as Claude Code, Codex, or comparable repository-aware tools.

The AI agent must work incrementally and maintain architectural consistency throughout the project.

### Required development rules

1. Inspect the existing repository before changing files.
2. Never overwrite or delete existing work without understanding its purpose.
3. Read project instructions and relevant documentation before implementation.
4. Establish and maintain an architecture decision record (ADR) for important decisions.
5. Implement one clearly defined module or vertical slice at a time.
6. Define acceptance criteria before writing code.
7. Write migrations, business logic, authorization, and tests together.
8. Run the relevant tests after each implementation step.
9. Review security, query performance, and error handling.
10. Document new endpoints, configuration variables, and integration contracts.
11. Avoid unnecessary dependencies and speculative features.
12. Never hardcode secrets or production credentials.
13. Never disable tests, validation, authorization, or security controls merely to make a build pass.
14. Do not report tests as passing unless they were actually executed and passed.
15. Keep implementation aligned with the approved architecture and ADRs.
16. Request human approval for irreversible data operations, production deployments, live payment changes, and major architectural deviations.
17. Maintain a changelog of completed work, outstanding issues, and known limitations.

Use repository-level AI instructions, such as `AGENTS.md` or the equivalent supported by the selected coding agent.

---

## 16. Required Phase-Wise Development Roadmap

Produce a detailed, dependency-aware development roadmap.

For every phase, specify:

- Objectives
- Business value
- Scope
- Modules involved
- Prerequisites
- Detailed implementation tasks
- Expected files and deliverables
- Database changes
- API changes
- UI changes
- Security considerations
- Automated tests
- Acceptance criteria
- Definition of Done
- Risks and mitigations
- Estimated effort in person-days
- Dependencies on external providers
- AI agent instructions
- Human review checkpoints

Use the following initial phase structure, modifying it when your analysis demonstrates a better sequence.

### Phase 0: Discovery and feasibility
Clarify business requirements, user personas, product types, operational processes, payment providers, shipping requirements, and MVP boundaries.

### Phase 1: Architecture and project foundation
Approve architecture, repository conventions, coding standards, environment setup, CI, configuration, logging, exception handling, and foundational database decisions.

### Phase 2: Identity, access control, and localization
Implement customer and administrator authentication, roles, permissions, account security, English/Bangla language infrastructure, and localization tests.

### Phase 3: Catalog and product information management
Implement categories, brands, product types, translations, variants, attributes, media, pricing, and catalog administration.

### Phase 4: Search and storefront
Implement the homepage, category pages, product details, search, filters, sorting, pagination, SEO metadata, and responsive Blade components.

### Phase 5: Cart and checkout
Implement cart persistence, address management, shipping selection, pricing calculation, coupons, checkout validation, and order creation.

### Phase 6: Orders, payments, and reconciliation
Implement the order lifecycle, payment abstraction, initial payment integration, COD, callback verification, idempotency, refunds, and reconciliation.

### Phase 7: Inventory and fulfillment
Implement stock management, stock reservations, warehouses where required, shipment creation, courier abstraction, tracking, delivery failures, and returns.

### Phase 8: Digital products and service bookings
Implement secure digital delivery and service-specific booking and fulfillment workflows. Separate them from physical inventory workflows.

### Phase 9: Customer account and engagement
Implement order history, tracking, notifications, reviews, wishlists, returns, support, and customer communication.

### Phase 10: Administration, reports, and CMS
Complete operational dashboards, reporting, content management, audit logs, exports, and administrative workflows.

### Phase 11: Mobile API
Implement and document the versioned API, authentication, catalog, cart, checkout, orders, payments, bookings, and mobile-specific notification support.

### Phase 12: Hardening and pre-production
Perform end-to-end testing, security review, load testing, integration testing, backup restoration, accessibility checks, and production-readiness assessment.

### Phase 13: Production launch
Prepare deployment automation, monitoring, alerting, backup schedules, support procedures, payment-provider verification, rollout strategy, and rollback plans.

### Phase 14: Post-launch improvements
Prioritize conversion optimization, additional payment and courier providers, advanced search, subscriptions, B2B features, marketplace functionality, and international expansion based on actual business demand.

You may combine or split phases if necessary, but preserve dependency ordering and explain any changes.

---

## 17. Required Architecture Documentation Deliverables

Generate a structured documentation package in Markdown.

Create the following files under a proposed `docs/architecture/` directory:

1. `PROJECT_OVERVIEW.md`
2. `BUSINESS_REQUIREMENTS.md`
3. `MVP_SCOPE_AND_PRIORITIES.md`
4. `SYSTEM_ARCHITECTURE.md`
5. `ARCHITECTURE_DECISION_RECORDS.md`
6. `DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md`
7. `DATABASE_DESIGN.md`
8. `DATABASE_DATA_DICTIONARY.md`
9. `API_ARCHITECTURE.md`
10. `API_ENDPOINT_CATALOG.md`
11. `WEB_UI_UX_GUIDELINES.md`
12. `BANGLADESH_LOCALIZATION_AND_COMMERCE.md`
13. `PAYMENT_INTEGRATION_DESIGN.md`
14. `SHIPPING_AND_FULFILLMENT_DESIGN.md`
15. `SECURITY_AND_PRIVACY.md`
16. `PERFORMANCE_AND_SCALABILITY.md`
17. `TESTING_AND_QA_STRATEGY.md`
18. `DEPLOYMENT_AND_DEVOPS.md`
19. `REPORTING_AND_ANALYTICS.md`
20. `AI_DEVELOPMENT_GUIDELINES.md`
21. `PHASE_WISE_DEVELOPMENT_ROADMAP.md`
22. `RISK_REGISTER.md`
23. `DEFINITION_OF_DONE.md`
24. `OPEN_QUESTIONS_AND_ASSUMPTIONS.md`

Also create or specify:

- Mermaid system context diagram
- Mermaid container/component diagram
- Mermaid ER diagram
- Order lifecycle diagram
- Payment lifecycle diagram
- Inventory reservation flow
- Checkout sequence diagram
- API authentication flow
- Deployment architecture diagram
- Module dependency matrix
- Feature prioritization matrix
- MVP acceptance checklist

Ensure diagrams are consistent with the written architecture.

---

## 18. Research and Verification Requirements

Before finalizing recommendations, investigate current authoritative documentation for:

- The selected Laravel version
- PostgreSQL capabilities and supported versions
- Laravel authentication and API recommendations
- Relevant payment gateway documentation
- Courier API documentation
- Bangladesh digital commerce and consumer-protection requirements
- Relevant privacy, tax, and financial obligations
- Production hosting and backup requirements

Use official documentation wherever possible.

Clearly label:

- Verified facts
- Architectural recommendations
- Business assumptions
- Unverified provider capabilities
- Decisions requiring human approval

Include links and access dates for external references.

Do not invent API endpoints, payment capabilities, provider policies, legal obligations, or technical compatibility.

---

## 19. Quality Standards

The proposed architecture must be:

- Production-oriented
- Secure by design
- Maintainable by a professional development team
- Appropriate for a Bangladeshi commercial business
- English/Bangla ready
- Mobile API ready
- Suitable for multiple product and service types
- Testable and observable
- Performance-conscious
- Financially auditable
- Extensible without premature complexity

Every major design decision must include a short rationale, alternatives considered where appropriate, and the consequences of the decision.

Avoid generic recommendations. Give concrete module boundaries, table names, workflows, acceptance criteria, and implementation tasks.

---

## 20. Execution Instructions

Follow this sequence:

**Step 1 — Analyze:** Identify requirements, ambiguities, business assumptions, technical constraints, and risks.

**Step 2 — Clarify:** Ask a concise set of high-impact questions where answers materially affect the architecture. If information is unavailable, state reasonable provisional assumptions rather than blocking all progress.

**Step 3 — Architect:** Develop the target architecture, domain model, database design, integration approach, security model, and mobile API strategy.

**Step 4 — Prioritize:** Define the MVP and separate it from post-launch features.

**Step 5 — Document:** Produce the architecture documents and diagrams in the specified structure.

**Step 6 — Validate:** Check architectural consistency, payment and inventory correctness, authorization boundaries, database integrity, testing coverage, and operational readiness.

**Step 7 — Plan:** Produce the dependency-aware, phase-wise implementation roadmap with effort estimates and acceptance criteria.

**Step 8 — Prepare for implementation:** Create repository-level AI instructions, phase-specific coding prompts, a task backlog, and a repeatable workflow for implementing one feature at a time.

**Step 9 — Await approval:** Present the architecture summary, MVP scope, important decisions, unresolved questions, and roadmap for human review before starting major implementation work.

Do not attempt to generate the entire application in a single response or one massive coding operation.

Start with the discovery and architecture deliverables. Once approved, proceed phase by phase, validating each phase before moving forward.

## Final Objective

Deliver a coherent, professional e-commerce architecture and an actionable AI-assisted development plan that can guide the implementation of a secure, maintainable, bilingual Laravel + Blade + PostgreSQL platform for Bangladesh, with support for physical products, digital products, services, and future Android/iOS applications.

The final result must be sufficiently specific that an experienced developer or repository-aware AI coding agent can implement it systematically without repeatedly inventing architecture decisions or compromising data integrity, security, or maintainability.