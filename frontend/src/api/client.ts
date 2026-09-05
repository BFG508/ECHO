import type { CreateEchoRequest, CreateEchoResponse, RevealEchoResponse } from '../types/echo.js';

interface ErrorEnvelope {
  error?: {
    code?: string;
    message?: string;
  };
}

export class ApiError extends Error {
  public constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

async function parseJsonResponse<T>(response: Response): Promise<T> {
  let payload: unknown;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError(response.status, 'invalid_response', 'The server returned an invalid response.');
  }

  if (!response.ok) {
    const error = payload as ErrorEnvelope;
    throw new ApiError(
      response.status,
      error.error?.code ?? 'request_failed',
      error.error?.message ?? 'The request could not be completed.',
    );
  }

  return payload as T;
}

export async function createEcho(payload: CreateEchoRequest): Promise<CreateEchoResponse> {
  const response = await fetch('/api/echoes', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    cache: 'no-store',
    credentials: 'omit',
  });
  return parseJsonResponse<CreateEchoResponse>(response);
}

export async function revealEcho(id: string, accessToken: string): Promise<RevealEchoResponse> {
  const response = await fetch(`/api/echoes/${encodeURIComponent(id)}/reveal`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accessToken }),
    cache: 'no-store',
    credentials: 'omit',
  });
  return parseJsonResponse<RevealEchoResponse>(response);
}
