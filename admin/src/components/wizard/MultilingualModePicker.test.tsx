import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { MultilingualModePicker } from './MultilingualModePicker';

describe('MultilingualModePicker', () => {
  it('renders nothing for single-language sites', () => {
    const { container } = render(
      <MultilingualModePicker value="single" onChange={vi.fn()} detectedLanguages={['en_US']} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders three radio options when multiple languages are detected', () => {
    render(
      <MultilingualModePicker
        value="B"
        onChange={vi.fn()}
        detectedLanguages={['en_US', 'et_EE']}
      />,
    );

    expect(screen.getAllByRole('radio')).toHaveLength(3);
    expect(screen.getByText(/separate smaily accounts/i)).toBeInTheDocument();
    expect(screen.getByText(/per-language automations/i)).toBeInTheDocument();
    expect(screen.getByText(/automation with branches/i)).toBeInTheDocument();
  });

  it('marks the currently-selected mode as checked', () => {
    render(
      <MultilingualModePicker
        value="A"
        onChange={vi.fn()}
        detectedLanguages={['en_US', 'et_EE']}
      />,
    );

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    const checked = radios.find((r) => r.checked);
    expect(checked?.value).toBe('A');
  });

  it('dispatches onChange with the picked mode value', () => {
    const onChange = vi.fn();
    render(
      <MultilingualModePicker
        value="B"
        onChange={onChange}
        detectedLanguages={['en_US', 'et_EE']}
      />,
    );

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    const cRadio = radios.find((r) => r.value === 'C');

    fireEvent.click(cRadio!);
    expect(onChange).toHaveBeenCalledWith('C');
  });
});
