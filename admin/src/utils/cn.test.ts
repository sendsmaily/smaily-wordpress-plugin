import { describe, expect, it } from 'vitest';

import { cn } from './cn';

/**
 * cn() is small and trivially composed of clsx + tailwind-merge, but the
 * tests below pin the behaviours every primitive relies on:
 *   1. Conditional class application via boolean expressions and objects.
 *   2. tailwind-merge precedence — later utility wins within a group.
 *
 * If we ever swap the underlying libraries, these tests catch regressions
 * before the primitives start producing wrong-looking output.
 */
describe('cn()', () => {
  it('concatenates plain strings', () => {
    expect(cn('p-4', 'text-brand')).toBe('p-4 text-brand');
  });

  it('drops falsy inputs', () => {
    expect(cn('p-4', false, null, undefined, '')).toBe('p-4');
  });

  it('expands conditional expressions', () => {
    const isActive = true;
    const isDisabled = false;
    expect(cn('p-4', isActive && 'bg-brand', isDisabled && 'opacity-50')).toBe('p-4 bg-brand');
  });

  it('expands object syntax', () => {
    expect(cn('p-4', { 'bg-brand': true, 'opacity-50': false })).toBe('p-4 bg-brand');
  });

  it('resolves tailwind utility precedence (later wins)', () => {
    expect(cn('p-4', 'p-2')).toBe('p-2');
    expect(cn('text-text-primary', 'text-brand-soft-text')).toBe('text-brand-soft-text');
  });

  it('preserves utilities from different functional groups', () => {
    expect(cn('p-4', 'bg-brand', 'rounded-lg').split(' ')).toEqual(
      expect.arrayContaining(['p-4', 'bg-brand', 'rounded-lg']),
    );
  });

  it('accepts arrays as input (clsx pass-through)', () => {
    expect(cn(['p-4', 'bg-brand'])).toBe('p-4 bg-brand');
  });
});
