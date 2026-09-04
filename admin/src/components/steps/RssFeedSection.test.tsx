import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { type RssFeedBootData } from '../../state/types';
import { wizardInitialState } from '../../state/wizard-reducer';
import { RssFeedSection } from './RssFeedSection';
import { Step5Integrations } from './Step5Integrations';

const rss: RssFeedBootData = {
  baseUrl: 'https://shop.test/smaily-rss-feed',
  categories: [
    { slug: 'hoodies', name: 'Hoodies' },
    { slug: 'tshirts', name: 'T-shirts' },
  ],
  defaults: {
    limit: 50,
    category: '',
    sortBy: 'modified',
    order: 'DESC',
    taxRate: 0,
  },
};

function feedUrlInput(): HTMLInputElement {
  return screen.getByLabelText<HTMLInputElement>('Feed URL');
}

describe('RssFeedSection', () => {
  it('prefills the builder from the legacy option defaults', () => {
    render(<RssFeedSection rss={{ ...rss, defaults: { ...rss.defaults, limit: 25, category: 'hoodies' } }} />);

    expect(screen.getByLabelText('Limit')).toHaveValue(25);
    expect(screen.getByLabelText('Product category')).toHaveValue('hoodies');
    expect(feedUrlInput().value).toBe(
      'https://shop.test/smaily-rss-feed?category=hoodies&limit=25&order_by=modified&order=DESC&tax_rate=0',
    );
  });

  it('updates the URL live when a field changes', () => {
    render(<RssFeedSection rss={rss} />);

    fireEvent.change(screen.getByLabelText('Product category'), {
      target: { value: 'tshirts' },
    });

    expect(feedUrlInput().value).toContain('category=tshirts');
  });

  it('lists All plus every category from the boot payload', () => {
    render(<RssFeedSection rss={rss} />);

    const select = screen.getByLabelText('Product category');
    const labels = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
    expect(labels).toEqual(['All', 'Hoodies', 'T-shirts']);
  });

  it('copies the URL to the clipboard and flashes confirmation', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
    });
    render(<RssFeedSection rss={rss} />);

    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

    expect(writeText).toHaveBeenCalledWith(feedUrlInput().value);
    expect(await screen.findByRole('button', { name: /copied/i })).toBeInTheDocument();
  });
});

describe('Step5Integrations RSS gating', () => {
  it('renders the RSS section when the boot payload carries rss data', () => {
    const state = {
      ...wizardInitialState,
      env: { ...wizardInitialState.env, rss },
    };
    render(<Step5Integrations state={state} />);

    expect(screen.getByText('Product RSS feed')).toBeInTheDocument();
  });

  it('hides the RSS section when WooCommerce is inactive (rss null)', () => {
    const state = {
      ...wizardInitialState,
      env: { ...wizardInitialState.env, rss: null },
    };
    render(<Step5Integrations state={state} />);

    expect(screen.queryByText('Product RSS feed')).not.toBeInTheDocument();
  });
});
