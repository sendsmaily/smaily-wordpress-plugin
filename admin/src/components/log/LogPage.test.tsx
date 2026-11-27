import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import * as eventsApi from '../../api/events';
import { LogPage } from './LogPage';

/** CON-464: the Event Log on its own Log page. */
describe('LogPage', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    window.history.replaceState({}, '', '/');
  });

  function listEvents(): ReturnType<typeof vi.spyOn> {
    return vi
      .spyOn(eventsApi, 'listEvents')
      .mockResolvedValue({ events: [], total: 0, page: 1, per_page: 50, failed_24h: 0 });
  }

  it('shows the Event Log under the page title', async () => {
    const list = listEvents();

    render(<LogPage />);

    expect(screen.getByRole('heading', { name: 'Smaily Connect — Log' })).toBeInTheDocument();
    await waitFor(() => expect(list).toHaveBeenCalledWith(expect.objectContaining({ status: '' }), expect.anything()));
  });

  it('opens on the failed rows when the address asks for them', async () => {
    window.history.replaceState({}, '', '/?page=smaily-connect-log&status=failed');
    const list = listEvents();

    render(<LogPage />);

    await waitFor(() => expect(list).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }), expect.anything()));
  });

  it('ignores a status the filter does not offer', async () => {
    window.history.replaceState({}, '', '/?status=everything');
    const list = listEvents();

    render(<LogPage />);

    await waitFor(() => expect(list).toHaveBeenCalledWith(expect.objectContaining({ status: '' }), expect.anything()));
  });
});
