import { type LabelHTMLAttributes, type ReactNode } from 'react';

import { __ } from '@admin/lib/i18n';

import { cn } from '../../utils/cn';

export interface LabelProps extends LabelHTMLAttributes<HTMLLabelElement> {
  /** Renders a "(optional)" hint at the end of the label text. */
  optional?: boolean;
  /** Renders a red asterisk after the label text. */
  required?: boolean;
  children: ReactNode;
}

/**
 * Form label primitive — the standalone <label> the step components
 * wrap around Input / Select / NumberInput. Distinct from the
 * Checkbox / Radio / Toggle "row" labels because those embed the
 * label inside the control wrapper.
 *
 * `htmlFor` must come from the caller — we don't auto-generate ids
 * because react-hook-form-style consumers usually own them.
 */
export function Label({
  optional,
  required,
  className,
  children,
  ...rest
}: LabelProps): React.JSX.Element {
  return (
    <label
      className={cn(
        'block text-sm font-medium text-text-primary',
        className,
      )}
      {...rest}
    >
      {children}
      {required && (
        <span className="ml-0.5 text-danger" aria-hidden>
          *
        </span>
      )}
      {optional && (
        <span className="ml-1 text-xs font-normal text-text-tertiary">{__( '(optional)', 'smaily-connect' )}</span>
      )}
    </label>
  );
}
