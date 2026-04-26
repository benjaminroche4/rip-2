# `templates/` — guide d'organisation

Ce dossier suit une convention en 4 niveaux. Si tu hésites où mettre un nouveau
fichier, demande-toi *à quoi il sert* avant *où le poser*.

## Arborescence

```
templates/
├── base.html.twig            ← layout principal, allégé
├── _partials/                ← partials techniques (PAS des composants)
│   ├── meta.html.twig          (OG/Twitter/canonical/hreflang/preconnect)
│   ├── schema/                 (JSON-LD réutilisables)
│   │   ├── organization.html.twig
│   │   ├── webpage.html.twig
│   │   ├── article.html.twig
│   │   ├── property.html.twig
│   │   └── breadcrumb.html.twig
│   └── tracking/               (GTM head + body)
├── components/              ← TOUS les composants Twig
│   │  -- UI primitives shadcn-like --
│   ├── Button.html.twig          → <twig:Button>
│   ├── Badge.html.twig           → <twig:Badge>
│   ├── Input.html.twig           → <twig:Input>
│   ├── Pagination.html.twig
│   ├── Accordion/{Item, Trigger, Content}.html.twig   → <twig:Accordion:Item>
│   ├── Tooltip/{Trigger, Content}
│   └── …
│   │  -- Layout chrome --
│   ├── Layout/Header.html.twig                        → <twig:Layout:Header>
│   ├── Layout/Header/MobileMenu.html.twig             → <twig:Layout:Header:MobileMenu>
│   └── Layout/Footer.html.twig
│   │  -- Page sections réutilisables --
│   ├── Section/Hero.html.twig
│   ├── Section/CtaFooter.html.twig
│   └── Section/Reviews.html.twig
│   │  -- Composants par bounded context (mirror src/) --
│   ├── Marketplace/{PropertyCard, PropertyGallery, Search}.html.twig
│   ├── Transport/{Line, Station}.html.twig
│   └── …
└── public/                  ← une vue = une route
    ├── home/index.html.twig
    ├── marketplace/{list, show}.html.twig
    ├── auth/login.html.twig
    └── …
```

## Décider où va un nouveau fichier

| C'est un / une… | Va dans… |
|---|---|
| Bout de HTML inclus depuis `base.html.twig` (meta, JSON-LD, GTM) | `_partials/` |
| Composant UI réutilisable sans logique métier (Button, Badge, Spinner) | `components/<Name>.html.twig` |
| Composant qui regroupe plusieurs pièces (Accordion, Breadcrumb, Tooltip) | `components/<Parent>/{Item, Trigger, Content}.html.twig` |
| Section de page (hero, CTA, testimonials) — peut apparaître sur plusieurs pages | `components/Section/<Name>.html.twig` |
| Composant *spécifique* à un domaine métier | `components/<Module>/<Name>.html.twig` (ex. `Marketplace/PropertyCard`) |
| Bout de chrome partagé (header, footer, mobile menu) | `components/Layout/<Name>.html.twig` |
| Vue d'une route Symfony | `public/<module>/<view>.html.twig` |
| Email | `emails/<flow>/<recipient>.html.twig` |

## Conventions

**Nommage**
- **Composants** : `PascalCase` (`Button`, `PropertyCard`, `MobileMenu`)
- **Pages** : `snake_case` (`reset_password`, `legal_notice`)
- **Partials techniques** : préfixe `_` (`_partials/meta.html.twig`)

**API d'un composant** — toujours documenter :

```twig
{# @prop variant 'default'|'secondary'  variant, default to 'default' #}
{# @prop size    'sm'|'md'|'lg'         size, default to 'md' #}
{# @block content The default block #}
{%- props variant = 'default', size = 'md' -%}

<button
    class="{{ style.apply({variant, size}, attributes.render('class'))|tailwind_merge }}"
    {{ attributes }}
>
    {%- block content %}{% endblock -%}
</button>
```

Les `{# @prop … %#}` rendent les props auto-complétables dans l'IDE PhpStorm /
Symfony Plugin et servent de doc minimale.

**Inheritance vs composition**
- `{% extends 'base.html.twig' %}` → réservé aux **pages** dans `public/`
- `<twig:Foo>` → pour les **composants** (jamais `{% include %}` pour un composant)
- `{% include '_partials/...' %}` → réservé aux **partials techniques**

**Données dans les templates**
- Les pages reçoivent des **DTOs typés** (`Property`, `Post`) ; Twig auto-resolve
  les getters publics. Évite l'accès `[]` côté PHP — c'est `->` désormais.
- Les composants typent leurs props via `@prop` et `props {…}`.
- **Pas d'`app.user` dans des composants potentiellement cacheables** — passer
  par un fragment (futur ESI) si besoin d'auth-aware.

## Lint avant commit

```bash
make lint     # twig + yaml + container
make test     # smoke tests controller
```

Le `Makefile` lance `lint:twig` aussi avant chaque `deploy`, pour fail-fast.

## Pièges connus

- `components/Marketplace/Search.html.twig` est rendu par la classe PHP
  `App\Marketplace\Twig\Components\MarketplaceSearch`. Le découplage est
  explicite via `#[AsLiveComponent(name: 'Marketplace:Search', template: …)]`
  — donc le tag `<twig:Marketplace:Search>` reste stable même si la classe
  est renommée.
- `twig/cache-extra` n'est pas installé — si tu veux `{% cache %}` pour
  des fragments lourds, fais d'abord `composer require twig/cache-extra`.
