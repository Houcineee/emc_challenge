/**
 * Théâtre Royal — Moteur 3D Interactif Three.js r128
 * Architecture complète : Scène, Proscénium, 400 Sièges modulables, Raycasting & Gestion des Holds
 */

(function () {
    let scene, camera, renderer, controls;
    let canvas, container;
    let seatsData = [];
    const seatMeshMap = new Map(); // seatId -> Mesh
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();
    let hoveredMesh = null;
    let eventId = null;
    let isStandingFrontStage = false;

    // Materials Palette
    const materials = {
        AVAILABLE: new THREE.MeshStandardMaterial({
            color: 0x10b981,
            metalness: 0.15,
            roughness: 0.35,
        }),
        HELD_BY_ME: new THREE.MeshStandardMaterial({
            color: 0xf59e0b,
            emissive: 0xb45309,
            emissiveIntensity: 0.45,
            metalness: 0.2,
            roughness: 0.25,
        }),
        HELD_BY_OTHER: new THREE.MeshStandardMaterial({
            color: 0x334155,
            metalness: 0.1,
            roughness: 0.8,
        }),
        SOLD: new THREE.MeshStandardMaterial({
            color: 0xef4444,
            metalness: 0.1,
            roughness: 0.7,
        }),
        STAGE_WOOD: new THREE.MeshStandardMaterial({
            color: 0x271911,
            roughness: 0.6,
            metalness: 0.1,
        }),
        VELVET_RED: new THREE.MeshStandardMaterial({
            color: 0x580816,
            roughness: 0.9,
            metalness: 0.05,
        }),
        GOLD_TRIM: new THREE.MeshStandardMaterial({
            color: 0xd97706,
            metalness: 0.8,
            roughness: 0.2,
        }),
        FLOOR_DARK: new THREE.MeshStandardMaterial({
            color: 0x0c0e15,
            roughness: 0.85,
        }),
        BRASS_RAIL: new THREE.MeshStandardMaterial({
            color: 0xf59e0b,
            metalness: 0.85,
            roughness: 0.25,
        })
    };

    function init() {
        canvas = document.getElementById('theater-canvas');
        if (!canvas) return;

        container = document.getElementById('theater-canvas-container');
        eventId = parseInt(canvas.getAttribute('data-event-id'), 10);
        isStandingFrontStage = canvas.getAttribute('data-is-standing') === 'true';

        // 1. Scene
        scene = new THREE.Scene();
        scene.background = new THREE.Color(0x07090e);
        scene.fog = new THREE.FogExp2(0x07090e, 0.012);

        // 2. Camera
        const aspect = container.clientWidth / container.clientHeight;
        camera = new THREE.PerspectiveCamera(48, aspect, 0.5, 300);
        camera.position.set(0, 22, 38);

        // 3. Renderer
        renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: false });
        renderer.setSize(container.clientWidth, container.clientHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        // 4. OrbitControls
        controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.minDistance = 12;
        controls.maxDistance = 85;
        controls.maxPolarAngle = Math.PI / 2 - 0.02; // Ne pas traverser le sol
        controls.target.set(0, 2.5, -6);
        controls.update();

        // 5. Lighting
        setupLighting();

        // 6. Architectural Room (Scène, Proscénium, Gradins)
        buildTheaterArchitecture();

        // 7. Load Seats from API
        loadSeats();

        // 8. Event Listeners
        window.addEventListener('resize', onWindowResize);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('click', onCanvasClick);

        // 9. Animation Loop
        animate();

        // 10. Polling automatique toutes les 15s pour synchroniser les réservations
        setInterval(loadSeatsSilent, 15000);
    }

    function setupLighting() {
        // Ambiance douce bleutée
        const ambientLight = new THREE.AmbientLight(0x232a3f, 1.2);
        scene.add(ambientLight);

        // Directional Light principale
        const dirLight = new THREE.DirectionalLight(0xfff1db, 1.0);
        dirLight.position.set(15, 35, 10);
        dirLight.castShadow = true;
        dirLight.shadow.mapSize.width = 2048;
        dirLight.shadow.mapSize.height = 2048;
        scene.add(dirLight);

        // Projecteur chaud doré focalisé sur la scène
        const stageSpot1 = new THREE.SpotLight(0xf59e0b, 2.5, 60, Math.PI / 4, 0.5, 1);
        stageSpot1.position.set(0, 25, -5);
        stageSpot1.target.position.set(0, 1, -25);
        scene.add(stageSpot1);
        scene.add(stageSpot1.target);

        const stageSpot2 = new THREE.SpotLight(0xffedd5, 1.8, 50, Math.PI / 5, 0.4, 1);
        stageSpot2.position.set(-15, 20, -10);
        stageSpot2.target.position.set(0, 1, -25);
        scene.add(stageSpot2);
        scene.add(stageSpot2.target);

        const stageSpot3 = new THREE.SpotLight(0xffedd5, 1.8, 50, Math.PI / 5, 0.4, 1);
        stageSpot3.position.set(15, 20, -10);
        stageSpot3.target.position.set(0, 1, -25);
        scene.add(stageSpot3);
        scene.add(stageSpot3.target);
    }

    function buildTheaterArchitecture() {
        // Sol principal de la salle
        const hallFloorGeo = new THREE.PlaneGeometry(80, 80);
        const hallFloorMesh = new THREE.Mesh(hallFloorGeo, materials.FLOOR_DARK);
        hallFloorMesh.rotation.x = -Math.PI / 2;
        hallFloorMesh.position.y = -0.05;
        hallFloorMesh.receiveShadow = true;
        scene.add(hallFloorMesh);

        // Scène principale (estrade surélevée bois)
        const stageGeo = new THREE.BoxGeometry(32, 1.6, 16);
        const stageMesh = new THREE.Mesh(stageGeo, materials.STAGE_WOOD);
        stageMesh.position.set(0, 0.8, -25);
        stageMesh.receiveShadow = true;
        scene.add(stageMesh);

        // Bordure dorée d'avant-scène
        const apronGeo = new THREE.CylinderGeometry(16, 16, 1.6, 32, 1, false, 0, Math.PI);
        const apronMesh = new THREE.Mesh(apronGeo, materials.STAGE_WOOD);
        apronMesh.position.set(0, 0.8, -17);
        scene.add(apronMesh);

        // Rideau de fond de scène en velours pourpre
        const curtainGeo = new THREE.PlaneGeometry(34, 16);
        const curtainMesh = new THREE.Mesh(curtainGeo, materials.VELVET_RED);
        curtainMesh.position.set(0, 8, -32.8);
        scene.add(curtainMesh);

        // Cadre de scène / Arche du Proscénium (Or royal)
        const archTopGeo = new THREE.BoxGeometry(36, 2, 2);
        const archTopMesh = new THREE.Mesh(archTopGeo, materials.GOLD_TRIM);
        archTopMesh.position.set(0, 16, -17);
        scene.add(archTopMesh);

        const archLeftGeo = new THREE.BoxGeometry(2, 16, 2);
        const archLeftMesh = new THREE.Mesh(archLeftGeo, materials.GOLD_TRIM);
        archLeftMesh.position.set(-17, 8, -17);
        scene.add(archLeftMesh);

        const archRightGeo = new THREE.BoxGeometry(2, 16, 2);
        const archRightMesh = new THREE.Mesh(archRightGeo, materials.GOLD_TRIM);
        archRightMesh.position.set(17, 8, -17);
        scene.add(archRightMesh);

        // Balustrades des Loges Latérales
        buildBoxBalcony(-24);
        buildBoxBalcony(24);
    }

    function buildBoxBalcony(xPos) {
        const floorGeo = new THREE.BoxGeometry(8, 0.8, 16);
        const floorMesh = new THREE.Mesh(floorGeo, materials.STAGE_WOOD);
        floorMesh.position.set(xPos, 4.0, -2);
        scene.add(floorMesh);

        // Rampe en laiton
        const railGeo = new THREE.CylinderGeometry(0.08, 0.08, 16);
        const railMesh = new THREE.Mesh(railGeo, materials.BRASS_RAIL);
        railMesh.rotation.x = Math.PI / 2;
        railMesh.position.set(xPos + (xPos > 0 ? -3.8 : 3.8), 5.2, -2);
        scene.add(railMesh);
    }

    // Low-poly Compound Chair Geometry
    function createChairMesh(material) {
        const chairGroup = new THREE.Group();

        // Coussin d'assise
        const seatGeo = new THREE.BoxGeometry(0.75, 0.16, 0.75);
        const seatPart = new THREE.Mesh(seatGeo, material);
        seatPart.position.set(0, 0.42, 0);
        seatPart.castShadow = true;
        chairGroup.add(seatPart);

        // Dossier
        const backGeo = new THREE.BoxGeometry(0.75, 0.8, 0.14);
        const backPart = new THREE.Mesh(backGeo, material);
        backPart.position.set(0, 0.85, 0.33);
        backPart.castShadow = true;
        chairGroup.add(backPart);

        // Pied central
        const legGeo = new THREE.CylinderGeometry(0.06, 0.06, 0.42, 6);
        const legPart = new THREE.Mesh(legGeo, materials.FLOOR_DARK);
        legPart.position.set(0, 0.21, 0);
        chairGroup.add(legPart);

        return chairGroup;
    }

    async function loadSeats() {
        const loader = document.getElementById('canvas-loader');
        try {
            const res = await fetch(`/api/events/${eventId}/seats`);
            const data = await res.json();
            seatsData = data.seats || [];
            renderAllSeats();
            if (loader) {
                loader.classList.add('opacity-0', 'pointer-events-none');
                setTimeout(() => loader.style.display = 'none', 300);
            }
        } catch (err) {
            console.error('Erreur chargement des sièges:', err);
            if (loader) loader.innerText = 'Erreur lors du chargement de la salle.';
        }
    }

    async function loadSeatsSilent() {
        try {
            const res = await fetch(`/api/events/${eventId}/seats`);
            const data = await res.json();
            seatsData = data.seats || [];
            updateSeatMaterialsOnly();
        } catch (e) {
            // ignore network glitch
        }
    }

    function renderAllSeats() {
        // Nettoyage éventuel des anciens meshes
        seatMeshMap.forEach(mesh => scene.remove(mesh));
        seatMeshMap.clear();

        const holdsForMe = [];

        seatsData.forEach(seat => {
            const status = seat.status || 'AVAILABLE';
            const zoneName = seat.zone ? seat.zone.name : '';
            const row = seat.row;
            const number = seat.number;
            const isSeated = seat.isSeated;

            if (status === 'HELD_BY_ME') {
                holdsForMe.push(seat);
            }

            // FRONT_STAGE en Fosse Debout (CONCERT)
            if (zoneName === 'FRONT_STAGE' && !isSeated) {
                renderStandingSpot(seat);
                return;
            }

            // Calcul spatial précis des coordonnées 3D
            const pos = computeSeatPosition(zoneName, row, number);
            const chair = createChairMesh(materials[status]);
            chair.position.set(pos.x, pos.y, pos.z);
            chair.rotation.y = pos.rotY;

            // userData pour le raycasting
            chair.userData = {
                seatId: seat.id,
                row: row,
                number: number,
                zoneName: zoneName,
                priceCents: seat.priceCents,
                status: status,
                holdTtl: seat.holdTtl,
                isSeated: true
            };

            // Rendre tous les enfants identifiables pour le raycast
            chair.traverse(child => {
                if (child.isMesh) {
                    child.userData = chair.userData;
                }
            });

            scene.add(chair);
            seatMeshMap.set(seat.id, chair);
        });

        updateCartDrawer(holdsForMe);
    }

    function renderStandingSpot(seat) {
        // En fosse debout, on positionne des spots lumineux au sol sur une grille
        const number = seat.number;
        const col = ((number - 1) % 20) - 9.5;
        const row = Math.floor((number - 1) / 20);

        const x = col * 1.35;
        const z = -14 + (row * 1.6);
        const y = 0.02;

        const status = seat.status || 'AVAILABLE';
        const geo = new THREE.CylinderGeometry(0.35, 0.35, 0.04, 16);
        const mesh = new THREE.Mesh(geo, materials[status]);
        mesh.position.set(x, y, z);

        mesh.userData = {
            seatId: seat.id,
            row: seat.row,
            number: seat.number,
            zoneName: 'FOSSE DEBOUT',
            priceCents: seat.priceCents,
            status: status,
            holdTtl: seat.holdTtl,
            isSeated: false
        };

        scene.add(mesh);
        seatMeshMap.set(seat.id, mesh);
    }

    function computeSeatPosition(zoneName, row, number) {
        let x = 0, y = 0, z = 0, rotY = 0;

        if (zoneName === 'FRONT_STAGE') {
            // 4 rangées A-D (80 places)
            const rowIndex = row.charCodeAt(0) - 'A'.charCodeAt(0); // 0 à 3
            const colIndex = number - 1; // 0 à 19
            x = (colIndex - 9.5) * 1.25;
            z = -15 + (rowIndex * 1.4);
            y = 0.05;
            rotY = 0;
        } else if (zoneName === 'ORCHESTRA') {
            // 8 rangées courbes E-L (160 places)
            const rowIndex = row.charCodeAt(0) - 'E'.charCodeAt(0); // 0 à 7
            const colIndex = number - 1; // 0 à 19

            const radius = 18 + (rowIndex * 2.1);
            const angleSpan = Math.PI * 0.52; // Éventail de 94 degrés
            const angle = (-angleSpan / 2) + (colIndex / 19) * angleSpan;

            x = Math.sin(angle) * radius;
            z = -12 + (Math.cos(angle) * radius * 0.7);
            y = 0.4 + (rowIndex * 0.36); // Incline douce gradinée
            rotY = -angle; // Oriente le siège face à la scène
        } else if (zoneName === 'LEFT_BOX') {
            // 3 rangées x 10 sièges flanc gauche
            const rowIndex = (number - 1) % 3;
            const seatIndex = Math.floor((number - 1) / 3);
            x = -24.5 + (rowIndex * 1.2);
            z = -8 + (seatIndex * 1.3);
            y = 4.0 + (rowIndex * 0.35);
            rotY = Math.PI * 0.35; // Orienté vers scène
        } else if (zoneName === 'RIGHT_BOX') {
            // 3 rangées x 10 sièges flanc droit
            const rowIndex = (number - 1) % 3;
            const seatIndex = Math.floor((number - 1) / 3);
            x = 24.5 - (rowIndex * 1.2);
            z = -8 + (seatIndex * 1.3);
            y = 4.0 + (rowIndex * 0.35);
            rotY = -Math.PI * 0.35; // Orienté vers scène
        } else if (zoneName === 'BALCONY') {
            // 5 rangées fortement gradinées M-Q au fond
            const rowIndex = row.charCodeAt(0) - 'M'.charCodeAt(0); // 0 à 4
            const colIndex = number - 1; // 0 à 19
            x = (colIndex - 9.5) * 1.35;
            z = 16 + (rowIndex * 2.0);
            y = 5.8 + (rowIndex * 0.75); // Gradinage élevé
            rotY = 0;
        }

        return { x, y, z, rotY };
    }

    function updateSeatMaterialsOnly() {
        const holdsForMe = [];
        seatsData.forEach(seat => {
            const chair = seatMeshMap.get(seat.id);
            if (chair) {
                const status = seat.status || 'AVAILABLE';
                chair.userData.status = status;
                chair.userData.holdTtl = seat.holdTtl;

                chair.traverse(child => {
                    if (child.isMesh && child.geometry.type === 'BoxGeometry') {
                        child.material = materials[status];
                    }
                });

                if (status === 'HELD_BY_ME') {
                    holdsForMe.push(seat);
                }
            }
        });
        updateCartDrawer(holdsForMe);
    }

    function onPointerMove(event) {
        const rect = canvas.getBoundingClientRect();
        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(scene.children, true);

        const tooltip = document.getElementById('seat-tooltip');

        let found = null;
        for (let i = 0; i < intersects.length; i++) {
            if (intersects[i].object.userData && intersects[i].object.userData.seatId) {
                found = intersects[i].object.userData;
                break;
            }
        }

        if (found) {
            canvas.style.cursor = 'pointer';
            tooltip.style.left = `${event.clientX - rect.left + 16}px`;
            tooltip.style.top = `${event.clientY - rect.top - 20}px`;
            tooltip.classList.remove('hidden');

            document.getElementById('tooltip-seat-name').innerText = `Rang ${found.row} — N° ${found.number}`;
            document.getElementById('tooltip-seat-zone').innerText = found.zoneName;
            document.getElementById('tooltip-seat-price').innerText = `${Math.round(found.priceCents / 100)} MAD`;

            const statusEl = document.getElementById('tooltip-seat-status');
            statusEl.innerText = formatStatusLabel(found.status);
            statusEl.className = formatStatusClass(found.status);
        } else {
            canvas.style.cursor = 'default';
            tooltip.classList.add('hidden');
        }
    }

    function formatStatusLabel(status) {
        switch (status) {
            case 'AVAILABLE': return 'Disponible';
            case 'HELD_BY_ME': return 'Dans votre panier';
            case 'HELD_BY_OTHER': return 'Réservé (15 min)';
            case 'SOLD': return 'Vendu';
            default: return status;
        }
    }

    function formatStatusClass(status) {
        const base = 'px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ';
        switch (status) {
            case 'AVAILABLE': return base + 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30';
            case 'HELD_BY_ME': return base + 'bg-amber-500/20 text-amber-400 border-amber-500/30';
            case 'HELD_BY_OTHER': return base + 'bg-slate-700/40 text-slate-400 border-slate-600/30';
            case 'SOLD': return base + 'bg-rose-500/20 text-rose-400 border-rose-500/30';
            default: return base;
        }
    }

    async function onCanvasClick(event) {
        const rect = canvas.getBoundingClientRect();
        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(scene.children, true);

        let targetData = null;
        for (let i = 0; i < intersects.length; i++) {
            if (intersects[i].object.userData && intersects[i].object.userData.seatId) {
                targetData = intersects[i].object.userData;
                break;
            }
        }

        if (!targetData) return;

        const seatId = targetData.seatId;
        const currentStatus = targetData.status;

        if (currentStatus === 'AVAILABLE') {
            await holdSeat(seatId);
        } else if (currentStatus === 'HELD_BY_ME') {
            await releaseSeat(seatId);
        } else if (currentStatus === 'SOLD') {
            showToast('Ce siège est déjà vendu pour ce spectacle.', 'error');
        } else if (currentStatus === 'HELD_BY_OTHER') {
            showToast('Ce siège est actuellement réservé par un autre utilisateur (15 min).', 'warning');
        }
    }

    async function holdSeat(seatId) {
        try {
            const res = await fetch(`/api/events/${eventId}/hold`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ seatIds: [seatId] })
            });

            const data = await res.json();

            if (res.ok) {
                showToast(`Siège réservé pendant 15 minutes !`, 'success');
                // Recharger l'état
                await loadSeats();
                if (typeof updateCartHeader === 'function') updateCartHeader();
            } else if (res.status === 401) {
                showToast('Authentification requise. Utilisez la barre rapide en haut (Client Alice).', 'warning');
            } else {
                showToast(data.error || 'Impossible de réserver ce siège.', 'error');
            }
        } catch (e) {
            showToast('Erreur réseau lors de la réservation.', 'error');
        }
    }

    async function releaseSeat(seatId) {
        try {
            const res = await fetch(`/api/events/${eventId}/hold/${seatId}`, {
                method: 'DELETE'
            });

            if (res.ok) {
                showToast('Siège libéré de votre panier.', 'info');
                await loadSeats();
                if (typeof updateCartHeader === 'function') updateCartHeader();
            } else {
                const data = await res.json();
                showToast(data.error || 'Erreur lors de la libération.', 'error');
            }
        } catch (e) {
            showToast('Erreur réseau.', 'error');
        }
    }

    // Expose global release function for the cart drawer UI
    window.releaseSeatFromCart = releaseSeat;

    function updateCartDrawer(holdsForMe) {
        const listEl = document.getElementById('held-seats-list');
        const countBadge = document.getElementById('held-count-badge');
        const totalEl = document.getElementById('held-total-price');
        const ctaBtn = document.getElementById('checkout-cta-button');

        if (!listEl) return;

        countBadge.innerText = `${holdsForMe.length} place${holdsForMe.length > 1 ? 's' : ''}`;

        if (holdsForMe.length === 0) {
            listEl.innerHTML = `<div class="text-center py-6 text-xs text-slate-400">Cliquez sur un siège vert pour le réserver pendant 15 minutes.</div>`;
            totalEl.innerText = '0 MAD';
            ctaBtn.classList.add('opacity-50', 'pointer-events-none');
            return;
        }

        ctaBtn.classList.remove('opacity-50', 'pointer-events-none');

        let totalCents = 0;
        let html = '';

        holdsForMe.forEach(s => {
            totalCents += s.priceCents;
            const priceMad = Math.round(s.priceCents / 100);
            const m = s.holdTtl ? Math.floor(s.holdTtl / 60) : 15;
            const sec = s.holdTtl ? (s.holdTtl % 60).toString().padStart(2, '0') : '00';

            html += `
                <div class="flex items-center justify-between p-2.5 rounded-xl bg-theater-900/90 border border-gold-500/20 text-xs">
                    <div>
                        <div class="font-bold text-white">Rang ${s.row} — N° ${s.number}</div>
                        <div class="text-[10px] text-gold-400">${s.zone ? s.zone.name : ''} • ${priceMad} MAD</div>
                        <div class="text-[10px] text-slate-400 font-mono">⏱️ expire dans ${m}:${sec}</div>
                    </div>
                    <button onclick="releaseSeatFromCart(${s.id})" class="px-2 py-1 rounded bg-red-950/40 hover:bg-red-900/60 text-red-300 border border-red-800/40 text-[11px] transition">
                        ✕
                    </button>
                </div>
            `;
        });

        listEl.innerHTML = html;
        totalEl.innerText = `${Math.round(totalCents / 100)} MAD`;
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `px-4 py-3 rounded-xl shadow-2xl backdrop-blur-xl border text-xs font-semibold flex items-center gap-2 transform transition-all duration-300 translate-y-2 opacity-0 pointer-events-auto`;

        if (type === 'success') {
            toast.className += ' bg-emerald-950/90 border-emerald-500/40 text-emerald-200';
            toast.innerHTML = `<span>✓</span> <span>${message}</span>`;
        } else if (type === 'error') {
            toast.className += ' bg-rose-950/90 border-rose-500/40 text-rose-200';
            toast.innerHTML = `<span>⚠</span> <span>${message}</span>`;
        } else if (type === 'warning') {
            toast.className += ' bg-amber-950/90 border-amber-500/40 text-amber-200';
            toast.innerHTML = `<span>⏳</span> <span>${message}</span>`;
        } else {
            toast.className += ' bg-theater-900/95 border-slate-700 text-slate-200';
            toast.innerHTML = `<span>ℹ</span> <span>${message}</span>`;
        }

        container.appendChild(toast);
        setTimeout(() => toast.classList.remove('translate-y-2', 'opacity-0'), 10);
        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // Camera Presets
    window.setCameraPreset = function (mode) {
        if (!controls || !camera) return;

        let targetPos, targetLookAt;

        if (mode === 'scene') {
            // Vue depuis la scène vers les spectateurs
            targetPos = new THREE.Vector3(0, 3, -24);
            targetLookAt = new THREE.Vector3(0, 5, 8);
        } else if (mode === 'top') {
            // Vue du dessus 2D
            targetPos = new THREE.Vector3(0, 55, 0.1);
            targetLookAt = new THREE.Vector3(0, 0, 0);
        } else {
            // Vue Panoramique par défaut
            targetPos = new THREE.Vector3(0, 22, 38);
            targetLookAt = new THREE.Vector3(0, 2.5, -6);
        }

        animateCamera(targetPos, targetLookAt, 800);
    };

    function animateCamera(targetPos, targetLookAt, durationMs) {
        const startPos = camera.position.clone();
        const startTarget = controls.target.clone();
        const startTime = performance.now();

        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / durationMs, 1);
            const ease = 0.5 - Math.cos(progress * Math.PI) / 2; // easeInOut

            camera.position.lerpVectors(startPos, targetPos, ease);
            controls.target.lerpVectors(startTarget, targetLookAt, ease);
            controls.update();

            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }
        requestAnimationFrame(step);
    }

    function onWindowResize() {
        if (!camera || !renderer || !container) return;
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    }

    function animate() {
        requestAnimationFrame(animate);
        if (controls) controls.update();
        if (renderer && scene && camera) {
            renderer.render(scene, camera);
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
