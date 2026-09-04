import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { emptyCredentials, idleAsync, type SmailyCredentials } from '../../state/types';
import { CredentialBlock } from './CredentialBlock';

/**
 * PRO-2286 — a store upgraded from the wordpress.org 2.0.0 package has
 * working credentials whose password never reaches the browser. Step 1
 * must be testable without retyping that secret, but only for the
 * account the server actually holds it for.
 */
function Harness({
  hasStoredPassword,
  initial,
}: {
  hasStoredPassword: boolean;
  initial: SmailyCredentials;
}): React.JSX.Element {
  const [credentials, setCredentials] = useState<SmailyCredentials>(initial);

  return (
    <CredentialBlock
      title="Smaily API credentials"
      credentials={credentials}
      connection={idleAsync}
      onCredentialsChange={(patch) => setCredentials((c) => ({ ...c, ...patch }))}
      onConnectionStart={vi.fn()}
      onConnectionSuccess={vi.fn()}
      onConnectionFailure={vi.fn()}
      idSuffix="default"
      hasStoredPassword={hasStoredPassword}
    />
  );
}

const storedAccount: SmailyCredentials = {
  ...emptyCredentials,
  subdomain: 'petshop',
  username: 'api',
};

describe('CredentialBlock — stored password', () => {
  it('lets the merchant verify the hydrated account with the password field empty', () => {
    render(<Harness hasStoredPassword initial={storedAccount} />);

    expect(screen.getByRole('button', { name: /test connection/i })).toBeEnabled();
    expect(screen.getByText(/leave empty to keep using the stored password/i)).toBeInTheDocument();
  });

  it('brings back the password requirement once another account is typed', () => {
    render(<Harness hasStoredPassword initial={storedAccount} />);

    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'otherstore' } });

    expect(screen.getByRole('button', { name: /test connection/i })).toBeDisabled();
    expect(
      screen.queryByText(/leave empty to keep using the stored password/i),
    ).not.toBeInTheDocument();
  });

  it('still demands a password when the server holds none', () => {
    render(<Harness hasStoredPassword={false} initial={storedAccount} />);

    expect(screen.getByRole('button', { name: /test connection/i })).toBeDisabled();
    expect(
      screen.queryByText(/leave empty to keep using the stored password/i),
    ).not.toBeInTheDocument();
  });
});
