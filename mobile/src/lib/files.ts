import { Directory, File, Paths } from 'expo-file-system';
import { Image } from 'expo-image';

/** Folder for PDFs the person opened (inside the cache, so the OS may also clear it). */
export function pdfDirectory(): Directory {
  const dir = new Directory(Paths.cache, 'acme-pdf');
  if (!dir.exists) dir.create({ intermediates: true });
  return dir;
}

export function deleteQuietly(uri: string | null | undefined): void {
  if (!uri) return;
  try {
    const f = new File(uri);
    if (f.exists) f.delete();
  } catch {
    // already gone
  }
}

/**
 * Sign-out: remove everything the app cached on disk – downloaded PDFs, camera photos,
 * compressed uploads and the image cache.
 */
export async function clearCachedFiles(): Promise<void> {
  try {
    for (const entry of Paths.cache.list()) {
      try {
        entry.delete();
      } catch {
        // in use or already gone
      }
    }
  } catch {
    // cache folder not readable
  }
  try {
    await Image.clearDiskCache();
    await Image.clearMemoryCache();
  } catch {
    // expo-image not ready
  }
}
