/**
 * Service Worker para Barcode Scanner
 * Gerencia cache offline, sincronização e fallback
 * 
 * Instalação: navigator.serviceWorker.register('/system/service-worker.js')
 */

const CACHE_NAME = 'barcode-cache-v1';
const SYNC_TAG = 'barcode-sync';

// Arquivos estáticos para cache na instalação
const STATIC_ASSETS = [
  '/system/barcode.html',
  '/system/api/installers.php',
  '/system/api/stock.php?action=get_all_items',
  'https://cdn.tailwindcss.com',
  'https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js',
  'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap'
];

/**
 * INSTALL - Cache resources estáticos
 */
self.addEventListener('install', event => {
  console.log('[SW] Instalando Service Worker...');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      console.log('[SW] Cache de assets criado');
      // Não falhar se algum asset não carregar
      return Promise.all(
        STATIC_ASSETS.map(url => 
          cache.add(url).catch(err => console.log(`[SW] Falha ao cachear ${url}:`, err))
        )
      );
    }).then(() => self.skipWaiting())
  );
});

/**
 * ACTIVATE - Limpar caches antigos
 */
self.addEventListener('activate', event => {
  console.log('[SW] Ativando Service Worker...');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('[SW] Deletando cache antigo:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

/**
 * FETCH - Estratégia: Network First, fallback para Cache, depois Offline
 */
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignorar solicitações não-GET
  if (request.method !== 'GET') {
    return;
  }

  // API calls: Tentar rede, cachear resposta, fallback para cache
  if (url.pathname.includes('/api/')) {
    event.respondWith(networkFirstStrategy(request));
    return;
  }

  // Assets estáticos: Cache first
  if (isStaticAsset(url)) {
    event.respondWith(cacheFirstStrategy(request));
    return;
  }

  // Padrão: Network first
  event.respondWith(networkFirstStrategy(request));
});

/**
 * Estratégia: Network First (tenta rede primeiro)
 */
async function networkFirstStrategy(request) {
  try {
    // Tentar rede
    const response = await fetch(request.clone());
    
    // Se sucesso, cachear e retornar
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    console.log('[SW] Erro de rede, tentando cache:', error);
    
    // Fallback para cache
    const cached = await caches.match(request);
    if (cached) {
      console.log('[SW] Retornando do cache');
      return cached;
    }

    // Nenhum cache disponível
    return createOfflineResponse();
  }
}

/**
 * Estratégia: Cache First (tenta cache primeiro)
 */
async function cacheFirstStrategy(request) {
  const cached = await caches.match(request);
  
  if (cached) {
    return cached;
  }

  try {
    const response = await fetch(request);
    
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    console.log('[SW] Erro ao carregar asset:', error);
    return createOfflineResponse();
  }
}

/**
 * Resposta genérica offline
 */
function createOfflineResponse() {
  return new Response(
    JSON.stringify({
      offline: true,
      error: 'Você está offline. Por favor, verifique sua conexão.'
    }),
    {
      status: 503,
      statusText: 'Service Unavailable',
      headers: new Headers({
        'Content-Type': 'application/json'
      })
    }
  );
}

/**
 * Verificar se URL é um asset estático
 */
function isStaticAsset(url) {
  return url.pathname.includes('.css') || 
         url.pathname.includes('.js') ||
         url.pathname.includes('.jpg') ||
         url.pathname.includes('.png') ||
         url.pathname.includes('.gif') ||
         url.pathname.includes('/fonts/');
}

/**
 * Sync - Sincronizar fila offline quando voltar online
 */
self.addEventListener('sync', event => {
  console.log('[SW] Evento de sincronização:', event.tag);
  
  if (event.tag === SYNC_TAG) {
    event.waitUntil(syncOfflineQueue());
  }
});

/**
 * Sincronizar fila de operações offline
 */
async function syncOfflineQueue() {
  try {
    const db = await openDB();
    const tx = db.transaction('pendingMoves', 'readonly');
    const store = tx.objectStore('pendingMoves');
    const moves = await getAllFromStore(store);

    console.log(`[SW] Sincronizando ${moves.length} movimentações pendentes...`);

    for (const move of moves) {
      try {
        const response = await fetch('/system/api/stock.php?action=add_movement', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(move)
        });

        if (response.ok) {
          // Remover da fila
          const deleteTx = db.transaction('pendingMoves', 'readwrite');
          deleteTx.objectStore('pendingMoves').delete(move.id);
          console.log('[SW] Movimentação sincronizada:', move.id);
        }
      } catch (error) {
        console.log('[SW] Erro ao sincronizar movimentação:', error);
        throw error; // Retentar depois
      }
    }

    // Notificar clientes
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
      client.postMessage({
        type: 'SYNC_COMPLETE',
        synced: moves.length
      });
    });

  } catch (error) {
    console.error('[SW] Erro ao sincronizar fila:', error);
    throw error;
  }
}

/**
 * Abrir IndexedDB
 */
function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('barcodeDB', 1);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);

    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('pendingMoves')) {
        db.createObjectStore('pendingMoves', { keyPath: 'id', autoIncrement: true });
      }
    };
  });
}

/**
 * Buscar todos os itens de uma store
 */
function getAllFromStore(store) {
  return new Promise((resolve, reject) => {
    const request = store.getAll();
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
}

console.log('[SW] Service Worker carregado');
