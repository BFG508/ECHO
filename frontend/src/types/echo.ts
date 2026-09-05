export interface CreateEchoRequest {
  id: string;
  ciphertext: string;
  iv: string;
  accessTokenHash: string;
  burnAfterReading: boolean;
  expiresInSeconds: number;
}

export interface CreateEchoResponse {
  id: string;
  createdAt: number;
  expiresAt: number;
}

export interface RevealEchoResponse {
  ciphertext: string;
  iv: string;
  burnAfterReading: boolean;
  createdAt: number;
  expiresAt: number;
}

export interface EncryptedEcho {
  id: string;
  key: string;
  accessToken: string;
  accessTokenHash: string;
  ciphertext: string;
  iv: string;
}

export interface ShareSecrets {
  id: string;
  key: string;
}
