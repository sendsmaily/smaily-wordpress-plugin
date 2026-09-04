import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'success';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Renders a spinner glyph + disables the button while truthy. */
  loading?: boolean;
  /** Left-aligned icon node (lucide-react etc). */
  leadingIcon?: ReactNode;
  /** Right-aligned icon node. */
  trailingIcon?: ReactNode;
}

/**
 * Primary admin-UI button. Variants follow STYLE_MAPPING.md:
 *   - primary  → brand pink (Step 1-5 Continue, default action)
 *   - secondary → outlined brand pink (cancel, secondary CTAs)
 *   - ghost    → no background, brand pink text (inline triggers)
 *   - danger   → red (destructive)
 *   - success  → emerald (Step 6 Finish — terminal-action color per
 *                STYLE_MAPPING.md §2.2)
 *
 * forwardRef so callers (modals, dropdowns) can focus the button
 * programmatically.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    loading = false,
    leadingIcon,
    trailingIcon,
    disabled,
    className,
    children,
    type = 'button',
    ...rest
  },
  ref,
) {
  const isDisabled = disabled || loading;

  return (
    <button
      ref={ref}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded font-medium',
        'transition-colors duration-120',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2',
        sizeClasses[size],
        variantClasses[variant],
        isDisabled && 'cursor-not-allowed opacity-60',
        className,
      )}
      {...rest}
    >
      {loading && <span className="scp-spin h-4 w-4 rounded-full border-2 border-current border-t-transparent" aria-hidden />}
      {!loading && leadingIcon && <span className="-ml-1 inline-flex">{leadingIcon}</span>}
      {children}
      {trailingIcon && <span className="-mr-1 inline-flex">{trailingIcon}</span>}
    </button>
  );
});

const sizeClasses: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-sm',
  md: 'h-10 px-4 text-base',
  lg: 'h-12 px-6 text-lg',
};

const variantClasses: Record<ButtonVariant, string> = {
  primary: 'bg-brand text-text-white hover:bg-brand-hover disabled:bg-brand-disabled',
  secondary:
    'border border-brand bg-surface text-brand hover:bg-brand-soft-bg disabled:border-brand-disabled disabled:text-brand-disabled',
  ghost: 'text-brand hover:bg-brand-soft-bg',
  danger: 'bg-danger text-text-white hover:bg-danger-hover',
  success: 'bg-success text-text-white hover:bg-success-hover',
};
