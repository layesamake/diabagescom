/**
 * Lecture de codes-barres par la caméra : détecteur natif du navigateur
 * (Chrome Android…) quand il existe, sinon moteur ZXing en WebAssembly
 * (iPhone, Firefox). Le fichier .wasm est servi par l'application elle-même :
 * aucun appel à un CDN, fonctionne aussi hors connexion une fois en cache.
 */
export type Detector = {
    detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
};

const FORMATS = [
    'ean_13',
    'ean_8',
    'upc_a',
    'upc_e',
    'code_128',
    'code_39',
    'itf',
    'qr_code',
] as const;

type NativeDetectorClass = {
    new (options: { formats: string[] }): Detector;
    getSupportedFormats: () => Promise<string[]>;
};

export async function createDetector(): Promise<Detector> {
    const native = (
        window as unknown as { BarcodeDetector?: NativeDetectorClass }
    ).BarcodeDetector;

    if (native) {
        const supported = await native
            .getSupportedFormats()
            .catch((): string[] => []);
        const formats = FORMATS.filter((f) => supported.includes(f));

        if (formats.includes('ean_13')) {
            return new native({ formats });
        }
    }

    const [{ BarcodeDetector, setZXingModuleOverrides }, wasm] =
        await Promise.all([
            import('barcode-detector/ponyfill'),
            import('zxing-wasm/reader/zxing_reader.wasm?url'),
        ]);
    setZXingModuleOverrides({
        locateFile: (path: string, prefix: string) =>
            path.endsWith('.wasm') ? wasm.default : prefix + path,
    });

    return new BarcodeDetector({ formats: [...FORMATS] });
}

/** Caméra arrière, assez précise pour lire un code-barres de près. */
export function openCamera(): Promise<MediaStream> {
    return navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1280 },
            height: { ideal: 720 },
        },
    });
}

export function cameraAvailable(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function' &&
        window.isSecureContext
    );
}

/** Petit bip de confirmation (sans fichier son) et vibration sur téléphone. */
export function signalScan(): void {
    try {
        const audio = new AudioContext();
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.frequency.value = 1200;
        gain.gain.value = 0.08;
        oscillator.connect(gain).connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.08);
        oscillator.onended = () => void audio.close();
    } catch {
        // Son indisponible : la vibration et l'affichage suffisent.
    }

    navigator.vibrate?.(60);
}
