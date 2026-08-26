import React, { useState } from 'react';
import { BasicType, components, createCustomBlock, BlockManager } from 'easy-email-core';
import { useBlock } from 'easy-email-editor';
import { BlockAttributeConfigurationManager } from 'easy-email-extensions';

const { Section, Column, Image, Text, Button } = components;

// Neutral gray placeholder shown in the editor canvas before a product/category
// is picked — a data URI so it renders with no network dependency.
const PLACEHOLDER_IMAGE =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200">' +
      '<rect width="300" height="200" fill="#e5e7eb"/>' +
      '<text x="150" y="104" font-family="Arial" font-size="14" fill="#9ca3af" text-anchor="middle">No image</text>' +
      '</svg>',
  );

function defaultValue() {
  return { id: null, name: '', image: '', price: '', url: '' };
}

/**
 * Both blocks share this shape: { id, name, image, price, url } cached at pick
 * time for a live editor preview. In 'production' mode (the actual HTML export
 * — see main.jsx's renderHtml) the picked fields are swapped for
 * {{product.<id>.field}} / {{category.<id>.field}} markers instead, which
 * Renderer.php resolves against the live catalog at send time so price and
 * stock stay current between designing a campaign and it actually going out.
 */
function makeCatalogBlock(catalogType, ctaLabel) {
  const blockType = `ysr/${catalogType}`;

  return createCustomBlock({
    name: catalogType === 'product' ? 'Product' : 'Category',
    type: blockType,
    create(payload) {
      return {
        type: blockType,
        data: { value: { ...defaultValue(), ...(payload?.data?.value || {}) } },
        attributes: {},
        children: [],
        ...payload,
      };
    },
    validParentType: [BasicType.PAGE, BasicType.WRAPPER],
    render({ data, mode }) {
      const value = data.data.value || defaultValue();
      const { id } = value;
      const useLiveTags = mode === 'production' && id;

      const image = useLiveTags ? `{{${catalogType}.${id}.image}}` : value.image || PLACEHOLDER_IMAGE;
      const name = useLiveTags
        ? `{{${catalogType}.${id}.name}}`
        : value.name || `Select a ${catalogType}…`;
      const price =
        catalogType === 'product' ? (useLiveTags ? `{{product.${id}.price}}` : value.price || '') : '';
      const url = useLiveTags ? `{{${catalogType}.${id}.url}}` : value.url || '#';

      return (
        <Section padding="20px 0">
          <Column>
            <Image src={image} href={url} width="100%" padding="0px 0px 12px 0px" />
            <Text align="center" font-size="16px" font-weight="bold" padding="0px 0px 4px 0px">
              {name}
            </Text>
            {price ? (
              <Text align="center" color="#666666" padding="0px 0px 12px 0px">
                {price}
              </Text>
            ) : null}
            <Button
              href={url}
              background-color="#2563eb"
              color="#ffffff"
              border-radius="6px"
              padding="0px"
            >
              {ctaLabel}
            </Button>
          </Column>
        </Section>
      );
    },
  });
}

const productBlock = makeCatalogBlock('product', 'Shop Now');
const categoryBlock = makeCatalogBlock('category', 'Shop Category');

export function registerCatalogBlocks() {
  // Keyed by the block's own `.type` — BlockManager.getBlockByType() looks up
  // blocksMap[type], so an arbitrary key here leaves the block unresolvable.
  BlockManager.registerBlocks({
    [productBlock.type]: productBlock,
    [categoryBlock.type]: categoryBlock,
  });
}

export const CATALOG_CATEGORY = {
  label: 'Catalog',
  active: true,
  blocks: [{ type: 'ysr/product' }, { type: 'ysr/category' }],
};

function CatalogPicker({ catalogType }) {
  const { focusBlock, setFocusBlockValue } = useBlock();
  const value = focusBlock?.data?.value || defaultValue();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);

  async function search(q) {
    setQuery(q);
    if (q.trim().length < 2) {
      setResults([]);
      return;
    }
    setSearching(true);
    try {
      const cfg = window.YsrEmailEditorConfig || {};
      const url = `${cfg.catalogSearchUrl}?type=${catalogType}&q=${encodeURIComponent(q)}`;
      const res = await fetch(url, { credentials: 'same-origin' });
      setResults(res.ok ? await res.json() : []);
    } finally {
      setSearching(false);
    }
  }

  function select(item) {
    setFocusBlockValue({ ...value, ...item });
    setResults([]);
    setQuery('');
  }

  return (
    <div style={{ padding: 16 }}>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 'bold', marginBottom: 6 }}>
        {catalogType === 'product' ? 'Search products' : 'Search categories'}
      </label>
      <input
        type="text"
        value={query}
        onChange={(e) => search(e.target.value)}
        placeholder="Type a name…"
        style={{ width: '100%', padding: 6, boxSizing: 'border-box' }}
      />
      {searching ? <div style={{ fontSize: 12, marginTop: 6 }}>Searching…</div> : null}
      {results.length > 0 ? (
        <ul style={{ listStyle: 'none', margin: '8px 0 0', padding: 0, maxHeight: 240, overflowY: 'auto' }}>
          {results.map((item) => (
            <li
              key={item.id}
              onClick={() => select(item)}
              style={{
                display: 'flex', alignItems: 'center', gap: 8, padding: 6, cursor: 'pointer',
                borderBottom: '1px solid #eee',
              }}
            >
              {item.image ? (
                <img src={item.image} alt="" style={{ width: 32, height: 32, objectFit: 'cover' }} />
              ) : null}
              <span style={{ fontSize: 13 }}>{item.name}</span>
            </li>
          ))}
        </ul>
      ) : null}
      {value.id ? (
        <div style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid #eee', fontSize: 13 }}>
          <strong>Selected:</strong> {value.name}
          {value.price ? ` — ${value.price}` : ''}
        </div>
      ) : (
        <div style={{ marginTop: 16, fontSize: 12, color: '#999' }}>
          Nothing selected yet — the placeholder image/text above will show until you pick one.
        </div>
      )}
    </div>
  );
}

export function registerCatalogAttributePanels() {
  BlockAttributeConfigurationManager.add({
    'ysr/product': () => <CatalogPicker catalogType="product" />,
    'ysr/category': () => <CatalogPicker catalogType="category" />,
  });
}
