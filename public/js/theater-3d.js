/**
 * Théâtre Royal — Moteur 3D Interactif Three.js r128
 * Architecture Authentique Inspirée de l'Opéra Garnier
 * - Proscénium baroque or & velours cramoisi plissé
 * - Parterre & Orchestre avec sol sombre épuré et visibilité 100% dégagée
 * - Balcon en fer à cheval à double niveau (Secteur annulaire creux extrudé THREE.Shape)
 * - Loges privées superposées (L1-L3, R1-R3) flanquant la scène à balustrades basses
 * - Grand lustre de cristal à bougeoirs dorés surélevé à y=26.0 (hors cône caméra)
 * - 400 fauteuils d'opéra sculptés en velours cramoisi & acajou sombre
 * - Chorégraphie caméra cinématique (Panoramique, Plan 2D, Vue Scène, Orchestre)
 */

(function () {
    let scene, camera, renderer, controls;
    let canvas, container;
    let seatsData = [];
    const seatMeshMap = new Map(); // seatId -> Group/Mesh
    const seatPickRoots = [];
    const raycaster = new THREE.Raycaster();
    const mouse = new THREE.Vector2();
    let hoveredSeatGroup = null;
    let originalHoverY = 0;
    let eventId = null;
    let isStandingFrontStage = false;
    let chandelierGroup = null;
    let cameraTween = null;
    let heldByMePulseT = 0;
    let countdownInterval = null;
    let zoneBannerTimeout = null;
    let hintDismissed = false;
    let pointerDownPos = { x: 0, y: 0 };

    // --- Palette de Matériaux Théâtraux & Historiques (Zéro néon) ---
    const materials = {
        AVAILABLE: new THREE.MeshStandardMaterial({
            color: 0x850d18, // Pourpre velours cramoisi profond
            roughness: 0.45,
            metalness: 0.1,
        }),
        HELD_BY_ME: new THREE.MeshStandardMaterial({
            color: 0xe5a93c, // Ambre doré rayonnant
            emissive: 0xd97706,
            emissiveIntensity: 0.42,
            roughness: 0.28,
            metalness: 0.2,
        }),
        HELD_BY_OTHER: new THREE.MeshStandardMaterial({
            color: 0x262024, // Noyer / ardoise sombre feutré
            roughness: 0.85,
            metalness: 0.05,
        }),
        SOLD: new THREE.MeshStandardMaterial({
            color: 0x181416, // Ébène / espresso sombre
            roughness: 0.9,
            metalness: 0.05,
        }),
        HOVER_SHEEN: new THREE.MeshStandardMaterial({
            color: 0x9e1220,
            emissive: 0xc59b3f,
            emissiveIntensity: 0.35,
            roughness: 0.35,
            metalness: 0.15,
        }),
        STAGE_PARQUET: new THREE.MeshStandardMaterial({
            color: 0x221610, // Chêne foncé verni de scène
            roughness: 0.52,
            metalness: 0.12,
        }),
        RISER_WOOD: new THREE.MeshStandardMaterial({
            color: 0x1a120e, // Parquet sombre de l'auditorium
            roughness: 0.72,
            metalness: 0.08,
        }),
        VELVET_DRAPE: new THREE.MeshStandardMaterial({
            color: 0x6a0a12, // Velours pourpre lourd pour draperies
            roughness: 0.88,
            metalness: 0.04,
            side: THREE.DoubleSide,
        }),
        GOLD_LEAF: new THREE.MeshStandardMaterial({
            color: 0xc59b3f, // Feuilles d'or patinées
            metalness: 0.82,
            roughness: 0.26,
        }),
        BRASS_TRIM: new THREE.MeshStandardMaterial({
            color: 0xe5b45c, // Laiton poli
            metalness: 0.88,
            roughness: 0.22,
        }),
        BALCONY_PANEL: new THREE.MeshStandardMaterial({
            color: 0x2a1b15, // Boiserie noyer des façades de loges
            roughness: 0.6,
            metalness: 0.1,
        }),
        CRYSTAL_GLASS: new THREE.MeshPhysicalMaterial({
            color: 0xffffff,
            roughness: 0.08,
            metalness: 0.1,
            transmission: 0.85,
            opacity: 0.85,
            transparent: true,
            ior: 1.52,
        }),
        CANDLE_EMISSIVE: new THREE.MeshStandardMaterial({
            color: 0xffe2a4,
            emissive: 0xffa526,
            emissiveIntensity: 1.4,
        }),
        CHAIR_WOOD: new THREE.MeshStandardMaterial({
            color: 0x18100c, // Acajou noirci pour armatures des sièges
            roughness: 0.48,
            metalness: 0.08,
        }),
        CHAIR_BRASS: new THREE.MeshStandardMaterial({
            color: 0xd4af37,
            metalness: 0.85,
            roughness: 0.25,
        })
    };

    // --- Géométries Partagées (Optimisation mémoire & draw calls) ---
    const sharedGeos = {
        cushion: new THREE.BoxGeometry(0.72, 0.14, 0.70),
        cushionBase: new THREE.BoxGeometry(0.74, 0.05, 0.72),
        backrest: new THREE.BoxGeometry(0.68, 0.68, 0.12),
        backFrame: new THREE.BoxGeometry(0.72, 0.72, 0.04),
        backCrest: new THREE.CylinderGeometry(0.36, 0.36, 0.05, 18),
        numberPlaque: new THREE.BoxGeometry(0.18, 0.07, 0.02),
        armrest: new THREE.BoxGeometry(0.09, 0.10, 0.54),
        armSupport: new THREE.BoxGeometry(0.05, 0.36, 0.06),
        armCap: new THREE.CylinderGeometry(0.045, 0.045, 0.09, 10),
        stanchion: new THREE.CylinderGeometry(0.04, 0.055, 0.36, 8),
        legFoot: new THREE.BoxGeometry(0.08, 0.04, 0.58),
        standingDisc: new THREE.CylinderGeometry(0.40, 0.40, 0.04, 20),
        standingRim: new THREE.TorusGeometry(0.41, 0.025, 6, 28),
        standingBadge: new THREE.CylinderGeometry(0.10, 0.10, 0.02, 8),
        crystalPrism: new THREE.OctahedronGeometry(0.16, 0),
        candleFlame: new THREE.ConeGeometry(0.06, 0.18, 8)
    };

    function init() {
        canvas = document.getElementById('theater-canvas');
        if (!canvas) return;

        container = document.getElementById('theater-canvas-container');
        eventId = parseInt(canvas.getAttribute('data-event-id'), 10);
        isStandingFrontStage = canvas.getAttribute('data-is-standing') === 'true';

        // 1. Scene
        scene = new THREE.Scene();
        scene.background = new THREE.Color(0x0C0A0B); // var(--ink)
        scene.fog = new THREE.FogExp2(0x0C0A0B, 0.006); // Lighter fog so far seats visible

        // 2. Camera (Preset Panoramique par défaut : vue d'ensemble sans aucune obstruction)
        const aspect = container.clientWidth / container.clientHeight;
        camera = new THREE.PerspectiveCamera(55, aspect, 0.5, 350);
        camera.position.set(0, 32, 42);

        // 3. Renderer
        renderer = new THREE.WebGLRenderer({
            canvas: canvas,
            antialias: true,
            alpha: false,
            powerPreference: 'high-performance'
        });
        renderer.setSize(container.clientWidth, container.clientHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;

        // 4. OrbitControls
        controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.minDistance = 10;
        controls.maxDistance = 110;
        controls.maxPolarAngle = Math.PI * 0.72; // Allow looking slightly upward from stage
        controls.target.set(0, 2, -4);
        controls.update();

        // 5. Lighting
        setupLighting();

        // 6. Architectural Room (Opéra Garnier Style sans aucune occlusion de sièges)
        buildOperaArchitecture();

        // 7. Load Seats from API
        loadSeats();

        // 9. Event Listeners
        window.addEventListener('resize', onWindowResize);
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('click', onCanvasClick);

        // 10. Animation Loop
        animate();

        // 11. Polling + live countdown (uniquement pour les représentations avec vente)
        if (canvas.getAttribute('data-mode') !== 'explore' && !isNaN(eventId)) {
            setInterval(loadSeatsSilent, 15000);
            countdownInterval = setInterval(() => {
                document.querySelectorAll('.seat-countdown').forEach(el => {
                    let ttl = parseInt(el.dataset.ttl, 10);
                    if (isNaN(ttl) || ttl <= 0) return;
                    ttl -= 1;
                    el.dataset.ttl = ttl;
                    const m = Math.floor(ttl / 60);
                    const s = (ttl % 60).toString().padStart(2, '0');
                    el.textContent = `${m}:${s}`;
                });
            }, 1000);
        }
    }

    function setupLighting() {
        // Ambiance chaude dorée feutrée
        const ambientLight = new THREE.AmbientLight(0x1a120e, 0.75);
        scene.add(ambientLight);

        // Lumière théâtrale zénithale principale
        const dirLight = new THREE.DirectionalLight(0xfff3e0, 0.9);
        dirLight.position.set(12, 38, 12);
        dirLight.castShadow = true;
        dirLight.shadow.mapSize.width = 2048;
        dirLight.shadow.mapSize.height = 2048;
        dirLight.shadow.camera.near = 10;
        dirLight.shadow.camera.far = 80;
        dirLight.shadow.camera.left = -30;
        dirLight.shadow.camera.right = 30;
        dirLight.shadow.camera.top = 30;
        dirLight.shadow.camera.bottom = -30;
        dirLight.shadow.bias = -0.0005;
        scene.add(dirLight);

        // Projecteur de scène doré focalisé sur le plateau
        const stageSpot1 = new THREE.SpotLight(0xf59e0b, 2.2, 55, Math.PI / 4.2, 0.45, 1);
        stageSpot1.position.set(0, 26, -6);
        stageSpot1.target.position.set(0, 1.2, -24);
        scene.add(stageSpot1);
        scene.add(stageSpot1.target);

        // Rampe d'avant-scène (Footlights) : uplight chaud doré à angle bas
        const footlight = new THREE.SpotLight(0xffaa55, 1.6, 28, Math.PI / 3, 0.75, 1);
        footlight.position.set(0, 1.2, -16.5);
        footlight.target.position.set(0, 8, -26);
        scene.add(footlight);
        scene.add(footlight.target);

        // Projecteurs latéraux croisés
        const leftSpot = new THREE.SpotLight(0xffecd2, 1.4, 45, Math.PI / 4.5, 0.4, 1);
        leftSpot.position.set(-18, 22, -8);
        leftSpot.target.position.set(0, 1.5, -24);
        scene.add(leftSpot);
        scene.add(leftSpot.target);

        const rightSpot = new THREE.SpotLight(0xffecd2, 1.4, 45, Math.PI / 4.5, 0.4, 1);
        rightSpot.position.set(18, 22, -8);
        rightSpot.target.position.set(0, 1.5, -24);
        scene.add(rightSpot);
        scene.add(rightSpot.target);

        // House fill: warm wash from behind proscenium into the auditorium
        const houseFill = new THREE.PointLight(0xfff0d0, 1.4, 90, 1.2);
        houseFill.position.set(0, 8, 10);
        scene.add(houseFill);

        // Secondary fill from high rear to catch balcony
        const rearFill = new THREE.PointLight(0xffe8c0, 0.8, 70, 1.4);
        rearFill.position.set(0, 20, 30);
        scene.add(rearFill);
    }

    // --- Générateur de Secteur Annulaire Creux (THREE.Shape & THREE.ExtrudeGeometry) ---
    function createAnnularSectorShape(innerR, outerR, angleSpan, zScale = 0.78, segments = 48) {
        const shape = new THREE.Shape();
        const halfAngle = angleSpan / 2;

        // 1. Point de départ sur l'arc intérieur à -halfAngle
        const x0 = Math.sin(-halfAngle) * innerR;
        const y0 = -Math.cos(-halfAngle) * innerR * zScale;
        shape.moveTo(x0, y0);

        // 2. Ligne radiale vers l'arc extérieur à -halfAngle
        shape.lineTo(Math.sin(-halfAngle) * outerR, -Math.cos(-halfAngle) * outerR * zScale);

        // 3. Arc extérieur de -halfAngle à +halfAngle
        for (let i = 1; i <= segments; i++) {
            const a = -halfAngle + (i / segments) * angleSpan;
            shape.lineTo(Math.sin(a) * outerR, -Math.cos(a) * outerR * zScale);
        }

        // 4. Ligne radiale vers l'arc intérieur à +halfAngle
        shape.lineTo(Math.sin(halfAngle) * innerR, -Math.cos(halfAngle) * innerR * zScale);

        // 5. Arc intérieur de +halfAngle retour vers -halfAngle
        for (let i = segments - 1; i >= 0; i--) {
            const a = -halfAngle + (i / segments) * angleSpan;
            shape.lineTo(Math.sin(a) * innerR, -Math.cos(a) * innerR * zScale);
        }

        shape.closePath();
        return shape;
    }

    function buildOperaArchitecture() {
        const houseGroup = new THREE.Group();

        // 1. Sol Principal de la Salle (Parquet sombre de l'auditorium sans aucune obstruction)
        const hallFloorGeo = new THREE.PlaneGeometry(90, 90);
        const hallFloorMesh = new THREE.Mesh(hallFloorGeo, materials.RISER_WOOD);
        hallFloorMesh.rotation.x = -Math.PI / 2;
        hallFloorMesh.position.y = -0.02;
        hallFloorMesh.receiveShadow = true;
        houseGroup.add(hallFloorMesh);

        // 2. Scène Principale (Main Stage) — strictement contenue à z <= -17.0
        const stageGeo = new THREE.BoxGeometry(36, 1.4, 16);
        const stageMesh = new THREE.Mesh(stageGeo, materials.STAGE_PARQUET);
        stageMesh.position.set(0, 0.7, -25); // s'étend de z = -33 à z = -17.0
        stageMesh.receiveShadow = true;
        houseGroup.add(stageMesh);

        // Nez de marche laiton doré sur le rebord avant de la scène (z = -17.0)
        const stageRimGeo = new THREE.BoxGeometry(36, 0.08, 0.15);
        const stageRim = new THREE.Mesh(stageRimGeo, materials.BRASS_TRIM);
        stageRim.position.set(0, 1.41, -17.0);
        houseGroup.add(stageRim);

        // Rampe de lampes d'avant-scène (Footlights décoratifs) strictement à z = -17.15
        for (let i = 0; i <= 14; i++) {
            const fx = -12 + (i / 14) * 24;
            const fz = -17.15;
            const lampBase = new THREE.Mesh(sharedGeos.armCap, materials.BRASS_TRIM);
            lampBase.position.set(fx, 1.45, fz);
            houseGroup.add(lampBase);
            const lampFlame = new THREE.Mesh(sharedGeos.candleFlame, materials.CANDLE_EMISSIVE);
            lampFlame.position.set(fx, 1.55, fz);
            houseGroup.add(lampFlame);
        }

        // 3. Rideau de Scène en Velours Plissé Majestueux (Strictement à z = -18.2)
        const drapeWidth = 32, drapeHeight = 15, segmentsX = 64;
        const curtainGeo = new THREE.PlaneGeometry(drapeWidth, drapeHeight, segmentsX, 4);
        const posAttr = curtainGeo.attributes.position;
        for (let i = 0; i < posAttr.count; i++) {
            const vx = posAttr.getX(i);
            const vz = Math.sin(vx * 2.8) * 0.28;
            posAttr.setZ(i, vz);
        }
        curtainGeo.computeVertexNormals();
        const curtainMesh = new THREE.Mesh(curtainGeo, materials.VELVET_DRAPE);
        curtainMesh.position.set(0, 8.5, -18.2);
        curtainMesh.receiveShadow = true;
        houseGroup.add(curtainMesh);

        // Frange dorée du bas de rideau
        const fringeGeo = new THREE.BoxGeometry(32, 0.3, 0.2);
        const fringeMesh = new THREE.Mesh(fringeGeo, materials.GOLD_LEAF);
        fringeMesh.position.set(0, 1.15, -18.1);
        houseGroup.add(fringeMesh);

        // 4. Cadre de Scène & Arche du Proscénium (Moulures baroques or royal à z = -17.4)
        const archHeight = 17;
        const archWidth = 36;
        const archBeamGeo = new THREE.BoxGeometry(archWidth, 1.8, 1.8);
        const archBeam = new THREE.Mesh(archBeamGeo, materials.GOLD_LEAF);
        archBeam.position.set(0, archHeight, -17.4);
        archBeam.castShadow = true;
        houseGroup.add(archBeam);

        // Lambrequin noble drapé sous l'arche (profil épuré sans swags proéminents)
        const valanceGeo = new THREE.BoxGeometry(32, 0.8, 0.2);
        const valanceMesh = new THREE.Mesh(valanceGeo, materials.VELVET_DRAPE);
        valanceMesh.position.set(0, archHeight - 1.1, -17.3);
        houseGroup.add(valanceMesh);

        const valanceTrimGeo = new THREE.BoxGeometry(32, 0.12, 0.25);
        const valanceTrim = new THREE.Mesh(valanceTrimGeo, materials.GOLD_LEAF);
        valanceTrim.position.set(0, archHeight - 1.5, -17.3);
        houseGroup.add(valanceTrim);

        // Cartouche royal / Médaillon sculpté au centre de l'arche
        const crestGeo = new THREE.CylinderGeometry(1.4, 1.4, 0.3, 24);
        const crestMesh = new THREE.Mesh(crestGeo, materials.GOLD_LEAF);
        crestMesh.rotation.x = Math.PI / 2;
        crestMesh.position.set(0, archHeight + 1.0, -17.2);
        houseGroup.add(crestMesh);

        // Colonnes corinthiennes cannelées gauche & droite
        const colGeo = new THREE.CylinderGeometry(0.85, 1.05, archHeight, 20);
        const colLeft = new THREE.Mesh(colGeo, materials.GOLD_LEAF);
        colLeft.position.set(-17.2, archHeight / 2, -17.4);
        colLeft.castShadow = true;
        houseGroup.add(colLeft);

        const colRight = new THREE.Mesh(colGeo, materials.GOLD_LEAF);
        colRight.position.set(17.2, archHeight / 2, -17.4);
        colRight.castShadow = true;
        houseGroup.add(colRight);

        // Chapiteaux ornementés
        const capGeo = new THREE.BoxGeometry(2.2, 1.2, 2.2);
        const capLeft = new THREE.Mesh(capGeo, materials.GOLD_LEAF);
        capLeft.position.set(-17.2, archHeight - 0.6, -17.4);
        houseGroup.add(capLeft);

        const capRight = new THREE.Mesh(capGeo, materials.GOLD_LEAF);
        capRight.position.set(17.2, archHeight - 0.6, -17.4);
        houseGroup.add(capRight);

        // 5. Parterre / Orchestre : Les anciens risers pleins en CylinderGeometry ont été
        //    COMPLÈTEMENT SUPPRIMÉS pour offrir une visibilité totale et dégagée sur les 160 sièges.

        // 6. Balcon en Fer à Cheval (Annular Hollow Sectors strictement à innerR >= 31.5)
        buildHorseshoeBalcony(houseGroup);

        // 7. Loges Privées Latérales Superposées (Stacked Private Box Suites)
        buildStackedPrivateBoxes(houseGroup, -24.0, true);
        buildStackedPrivateBoxes(houseGroup, 24.0, false);

        // 8. Grand Lustre Royal en Cristal surélevé à y = 26.0 (au-dessus du champ de vision)
        chandelierGroup = buildGrandChandelier();
        chandelierGroup.position.set(0, 26.0, -2);
        houseGroup.add(chandelierGroup);

        scene.add(houseGroup);
    }

    function buildHorseshoeBalcony(parentGroup) {
        // Deux galeries structurées autour d'un vide central : une vraie coursive
        // habitable au premier niveau, puis une galerie supérieure plus légère.
        const balconyTiers = [
            { yFloor: 6.25, innerR: 29.5, outerR: 39.5, angleSpan: Math.PI * 0.72, parapetH: 0.58, supportBottom: 0.9 },
            { yFloor: 11.8, innerR: 33.5, outerR: 40.5, angleSpan: Math.PI * 0.70, parapetH: 0.52, supportBottom: 7.2 }
        ];

        balconyTiers.forEach((tier, tierIndex) => {
            const zCenter = -11.5;
            const zScale = 0.78;

            // Dalle porteuse épaisse, avec un débord visible sous la galerie.
            const floorShape = createAnnularSectorShape(tier.innerR, tier.outerR, tier.angleSpan, zScale, 64);
            const floorGeo = new THREE.ExtrudeGeometry(floorShape, { depth: 0.48, bevelEnabled: false });
            const floorMesh = new THREE.Mesh(floorGeo, materials.RISER_WOOD);
            floorMesh.rotation.x = -Math.PI / 2;
            floorMesh.position.set(0, tier.yFloor, zCenter);
            floorMesh.receiveShadow = true;
            floorMesh.castShadow = true;
            parentGroup.add(floorMesh);

            // Cinq gradins courbes sous les fauteuils du balcon principal.
            // Chaque rangée repose ainsi sur une marche identifiable au lieu
            // de flotter au-dessus d'une dalle continue.
            if (tierIndex === 0) {
                for (let rowIndex = 0; rowIndex < 5; rowIndex++) {
                    const rowRadius = 32.2 + (rowIndex * 1.35);
                    const rowShape = createAnnularSectorShape(rowRadius - 0.62, rowRadius + 0.62, 0.92, zScale, 48);
                    const rowGeo = new THREE.ExtrudeGeometry(rowShape, { depth: 0.27, bevelEnabled: false });
                    const rowMesh = new THREE.Mesh(rowGeo, materials.RISER_WOOD);
                    rowMesh.rotation.x = -Math.PI / 2;
                    rowMesh.position.set(0, tier.yFloor + 0.53 + (rowIndex * 0.68), zCenter);
                    rowMesh.receiveShadow = true;
                    parentGroup.add(rowMesh);

                    const rowTrimShape = createAnnularSectorShape(rowRadius - 0.62, rowRadius + 0.62, 0.92, zScale, 48);
                    const rowTrimGeo = new THREE.ExtrudeGeometry(rowTrimShape, { depth: 0.035, bevelEnabled: false });
                    const rowTrim = new THREE.Mesh(rowTrimGeo, materials.BALCONY_PANEL);
                    rowTrim.rotation.x = -Math.PI / 2;
                    rowTrim.position.set(0, tier.yFloor + 0.81 + (rowIndex * 0.68), zCenter);
                    parentGroup.add(rowTrim);
                }
            }

            // Bandeau extérieur : la tranche de la dalle donne de la profondeur
            // et rattache visuellement le balcon à sa structure.
            const outerBandShape = createAnnularSectorShape(tier.outerR - 0.28, tier.outerR + 0.10, tier.angleSpan, zScale, 64);
            const outerBandGeo = new THREE.ExtrudeGeometry(outerBandShape, { depth: 0.78, bevelEnabled: false });
            const outerBand = new THREE.Mesh(outerBandGeo, materials.BALCONY_PANEL);
            outerBand.rotation.x = -Math.PI / 2;
            outerBand.position.set(0, tier.yFloor - 0.15, zCenter);
            outerBand.castShadow = true;
            parentGroup.add(outerBand);

            // Bandeau frontal sombre, puis filet doré sous la main courante.
            const fasciaShape = createAnnularSectorShape(tier.innerR - 0.22, tier.innerR + 0.26, tier.angleSpan, zScale, 64);
            const fasciaGeo = new THREE.ExtrudeGeometry(fasciaShape, { depth: 0.72, bevelEnabled: false });
            const fascia = new THREE.Mesh(fasciaGeo, materials.BALCONY_PANEL);
            fascia.rotation.x = -Math.PI / 2;
            fascia.position.set(0, tier.yFloor - 0.18, zCenter);
            fascia.castShadow = true;
            parentGroup.add(fascia);

            const trimShape = createAnnularSectorShape(tier.innerR - 0.28, tier.innerR + 0.06, tier.angleSpan, zScale, 64);
            const trimGeo = new THREE.ExtrudeGeometry(trimShape, { depth: 0.10, bevelEnabled: false });
            const trim = new THREE.Mesh(trimGeo, materials.BRASS_TRIM);
            trim.rotation.x = -Math.PI / 2;
            trim.position.set(0, tier.yFloor + tier.parapetH + 0.05, zCenter);
            parentGroup.add(trim);

            // Parapet frontal bas, complété par des balustres espacés.
            const parapetShape = createAnnularSectorShape(tier.innerR - 0.18, tier.innerR + 0.02, tier.angleSpan, zScale, 64);
            const parapetGeo = new THREE.ExtrudeGeometry(parapetShape, { depth: tier.parapetH, bevelEnabled: false });
            const parapet = new THREE.Mesh(parapetGeo, materials.BALCONY_PANEL);
            parapet.rotation.x = -Math.PI / 2;
            parapet.position.set(0, tier.yFloor, zCenter);
            parentGroup.add(parapet);

            const balusterCount = tierIndex === 0 ? 19 : 17;
            for (let i = 0; i < balusterCount; i++) {
                const angle = (-tier.angleSpan / 2) + (i / (balusterCount - 1)) * tier.angleSpan;
                const bx = Math.sin(angle) * (tier.innerR - 0.08);
                const bz = zCenter + Math.cos(angle) * (tier.innerR - 0.08) * zScale;
                const baluster = new THREE.Mesh(
                    new THREE.CylinderGeometry(0.055, 0.075, 0.64, 8),
                    materials.BRASS_TRIM
                );
                baluster.position.set(bx, tier.yFloor + tier.parapetH + 0.31, bz);
                parentGroup.add(baluster);
            }

            // Main courante continue, plus haute et plus lisible depuis les vues caméra.
            const railShape = createAnnularSectorShape(tier.innerR - 0.24, tier.innerR + 0.08, tier.angleSpan, zScale, 64);
            const railGeo = new THREE.ExtrudeGeometry(railShape, { depth: 0.11, bevelEnabled: false });
            const rail = new THREE.Mesh(railGeo, materials.BRASS_TRIM);
            rail.rotation.x = -Math.PI / 2;
            rail.position.set(0, tier.yFloor + tier.parapetH + 0.64, zCenter);
            parentGroup.add(rail);

            // Corbeaux et colonnes : chaque niveau repose visiblement sur le précédent.
            const supportCount = tierIndex === 0 ? 7 : 6;
            const supportHeight = tier.yFloor - tier.supportBottom;
            for (let i = 0; i < supportCount; i++) {
                const angle = (-tier.angleSpan / 2) + ((i + 0.5) / supportCount) * tier.angleSpan;
                const sx = Math.sin(angle) * (tier.outerR - 0.55);
                const sz = zCenter + Math.cos(angle) * (tier.outerR - 0.55) * zScale;

                const column = new THREE.Mesh(
                    new THREE.CylinderGeometry(0.22, 0.30, supportHeight, 12),
                    materials.BALCONY_PANEL
                );
                column.position.set(sx, tier.supportBottom + supportHeight / 2, sz);
                column.castShadow = true;
                parentGroup.add(column);

                const capital = new THREE.Mesh(
                    new THREE.BoxGeometry(0.72, 0.18, 0.72),
                    materials.GOLD_LEAF
                );
                capital.position.set(sx, tier.yFloor - 0.54, sz);
                capital.rotation.y = -angle;
                parentGroup.add(capital);
            }

            // Appliques murales dorées à bougies sur la façade du balcon.
            const sconceCount = 9;
            for (let i = 0; i < sconceCount; i++) {
                const angle = (-tier.angleSpan / 2) + (i / (sconceCount - 1)) * tier.angleSpan;
                const sx = Math.sin(angle) * (tier.innerR - 0.25);
                const sz = zCenter + Math.cos(angle) * (tier.innerR - 0.25) * zScale;

                const sconceBracket = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 0.25, 8), materials.GOLD_LEAF);
                sconceBracket.position.set(sx, tier.yFloor + (tier.parapetH / 2), sz);
                parentGroup.add(sconceBracket);

                const flame = new THREE.Mesh(sharedGeos.candleFlame, materials.CANDLE_EMISSIVE);
                flame.position.set(sx, tier.yFloor + (tier.parapetH / 2) + 0.18, sz);
                parentGroup.add(flame);
            }
        });
    }

    function buildStackedPrivateBoxes(parentGroup, posX, isLeft) {
        // 3 rangées superposées de loges de prestige (matching L1-L3 ou R1-R3)
        const levels = [
            { y: 3.4, label: 'Baignoires' },
            { y: 5.6, label: 'Loges de Balcon' },
            { y: 7.8, label: 'Loges de Galerie' }
        ];

        levels.forEach((lvl) => {
            const boxGroup = new THREE.Group();

            // Plancher en chêne de la loge sous les fauteuils
            const floorGeo = new THREE.BoxGeometry(6.5, 0.35, 16.5);
            const floorMesh = new THREE.Mesh(floorGeo, materials.RISER_WOOD);
            floorMesh.position.set(posX, lvl.y, -7.5);
            floorMesh.receiveShadow = true;
            boxGroup.add(floorMesh);

            // Balustrade frontale basse pour vue totalement dégagée sur les fauteuils
            const frontWallGeo = new THREE.BoxGeometry(0.3, 0.45, 16.5);
            const frontWall = new THREE.Mesh(frontWallGeo, materials.BALCONY_PANEL);
            frontWall.position.set(posX + (isLeft ? 3.1 : -3.1), lvl.y + 0.22, -7.5);
            boxGroup.add(frontWall);

            // Main courante en laiton poli
            const railGeo = new THREE.CylinderGeometry(0.05, 0.05, 16.5, 8);
            const rail = new THREE.Mesh(railGeo, materials.BRASS_TRIM);
            rail.rotation.x = Math.PI / 2;
            rail.position.set(posX + (isLeft ? 3.1 : -3.1), lvl.y + 0.48, -7.5);
            boxGroup.add(rail);

            // Appliques chandelier murales en arrière de loge (ne bloquent jamais les sièges)
            for (let c = 0; c < 3; c++) {
                const zPos = -13.5 + (c * 6.0);
                const sconce = new THREE.Mesh(new THREE.BoxGeometry(0.15, 0.15, 0.15), materials.GOLD_LEAF);
                sconce.position.set(posX + (isLeft ? -3.0 : 3.0), lvl.y + 1.6, zPos);
                boxGroup.add(sconce);

                const flame = new THREE.Mesh(sharedGeos.candleFlame, materials.CANDLE_EMISSIVE);
                flame.position.set(posX + (isLeft ? -3.0 : 3.0), lvl.y + 1.8, zPos);
                boxGroup.add(flame);
            }

            parentGroup.add(boxGroup);
        });
    }

    function buildGrandChandelier() {
        const group = new THREE.Group();

        // 1. Tige centrale de suspension et couronne dorée supérieure
        const rodGeo = new THREE.CylinderGeometry(0.08, 0.08, 4.5, 8);
        const rod = new THREE.Mesh(rodGeo, materials.GOLD_LEAF);
        rod.position.y = 2.25;
        group.add(rod);

        const canopyGeo = new THREE.CylinderGeometry(1.4, 0.4, 0.8, 16);
        const canopy = new THREE.Mesh(canopyGeo, materials.GOLD_LEAF);
        canopy.position.y = 1.0;
        group.add(canopy);

        // 2. Cerceau supérieur en laiton (Tier 1)
        const ring1Geo = new THREE.TorusGeometry(1.8, 0.08, 8, 24);
        const ring1 = new THREE.Mesh(ring1Geo, materials.GOLD_LEAF);
        ring1.rotation.x = Math.PI / 2;
        ring1.position.y = 0.2;
        group.add(ring1);

        // 3. Grand cerceau central à candélabres (Tier 2)
        const ring2Geo = new THREE.TorusGeometry(3.2, 0.12, 8, 32);
        const ring2 = new THREE.Mesh(ring2Geo, materials.BRASS_TRIM);
        ring2.rotation.x = Math.PI / 2;
        ring2.position.y = -0.8;
        group.add(ring2);

        // 4. Corbeille inférieure en cristal taillé (Tier 3)
        const basketGeo = new THREE.CylinderGeometry(2.8, 0.3, 2.2, 24, 2, true);
        const basket = new THREE.Mesh(basketGeo, materials.CRYSTAL_GLASS);
        basket.position.y = -1.9;
        group.add(basket);

        // 5. Pampilles de cristal taillé facettées disposées en cercles
        const pampilleCounts = [16, 24];
        pampilleCounts.forEach((count, tierIdx) => {
            const rad = tierIdx === 0 ? 1.8 : 3.2;
            const yPos = tierIdx === 0 ? 0.0 : -0.95;
            for (let i = 0; i < count; i++) {
                const angle = (i / count) * Math.PI * 2;
                const px = Math.cos(angle) * rad;
                const pz = Math.sin(angle) * rad;

                const prism = new THREE.Mesh(sharedGeos.crystalPrism, materials.CRYSTAL_GLASS);
                prism.position.set(px, yPos, pz);
                prism.scale.set(1.0, 1.8, 1.0);
                group.add(prism);
            }
        });

        // 6. Bougies rayonnantes autour du grand cerceau (16 candélabres)
        for (let i = 0; i < 16; i++) {
            const angle = (i / 16) * Math.PI * 2;
            const cx = Math.cos(angle) * 3.1;
            const cz = Math.sin(angle) * 3.1;

            const candleBody = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 0.35, 6), materials.GOLD_LEAF);
            candleBody.position.set(cx, -0.6, cz);
            group.add(candleBody);

            const flame = new THREE.Mesh(sharedGeos.candleFlame, materials.CANDLE_EMISSIVE);
            flame.position.set(cx, -0.38, cz);
            group.add(flame);
        }

        // 7. Source de lumière chaude centrale avec ombres douces
        const chandelierLight = new THREE.PointLight(0xffe2a4, 2.0, 60, 1.1);
        chandelierLight.position.set(0, -1.0, 0);
        chandelierLight.castShadow = true;
        chandelierLight.shadow.mapSize.width = 1024;
        chandelierLight.shadow.mapSize.height = 1024;
        chandelierLight.shadow.bias = -0.001;
        group.add(chandelierLight);

        return group;
    }

    // --- Géométrie Fauteuil d'Opéra de Luxe Sculpté ---
    function createLuxuryArmchairMesh(statusMaterial) {
        const chairGroup = new THREE.Group();

        // 1. Coussin d'assise moelleux en velours avec galbe
        const seatPart = new THREE.Mesh(sharedGeos.cushion, statusMaterial);
        seatPart.position.set(0, 0.44, 0);
        // Les 400 fauteuils restent éclairés sans lancer chacun une ombre :
        // cela réduit fortement le coût du rendu et évite les taches noires.
        seatPart.castShadow = false;
        seatPart.receiveShadow = false;
        seatPart.userData.isVelvet = true;
        chairGroup.add(seatPart);

        // Berceau inférieur sculpté en acajou sous le coussin
        const seatBase = new THREE.Mesh(sharedGeos.cushionBase, materials.CHAIR_WOOD);
        seatBase.position.set(0, 0.35, 0);
        chairGroup.add(seatBase);

        // 2. Dossier rembourré capitonné incliné en velours
        const backPart = new THREE.Mesh(sharedGeos.backrest, statusMaterial);
        backPart.rotation.x = 0.12; // Légère inclinaison arrière naturelle
        backPart.position.set(0, 0.88, 0.30);
        backPart.castShadow = false;
        backPart.userData.isVelvet = true;
        chairGroup.add(backPart);

        // Coque arrière en boiserie acajou sculptée
        const backFrame = new THREE.Mesh(sharedGeos.backFrame, materials.CHAIR_WOOD);
        backFrame.rotation.x = 0.12;
        backFrame.position.set(0, 0.88, 0.37);
        chairGroup.add(backFrame);

        // Fronton / Couronnement galbé sculpté au sommet du dossier
        const backCrest = new THREE.Mesh(sharedGeos.backCrest, materials.CHAIR_WOOD);
        backCrest.rotation.x = Math.PI / 2 + 0.12;
        backCrest.position.set(0, 1.22, 0.34);
        chairGroup.add(backCrest);

        // Plaque numéro de siège en laiton poli sur le dos du fauteuil
        const plaque = new THREE.Mesh(sharedGeos.numberPlaque, materials.CHAIR_BRASS);
        plaque.rotation.x = 0.12;
        plaque.position.set(0, 1.14, 0.40);
        chairGroup.add(plaque);

        // 3. Deux accoudoirs rembourrés avec manchons de laiton
        const armOffsets = [-0.38, 0.38];
        armOffsets.forEach(ox => {
            const armPad = new THREE.Mesh(sharedGeos.armrest, statusMaterial);
            armPad.position.set(ox, 0.58, 0.02);
            armPad.userData.isVelvet = true;
            chairGroup.add(armPad);

            const armSupport = new THREE.Mesh(sharedGeos.armSupport, materials.CHAIR_WOOD);
            armSupport.position.set(ox, 0.33, -0.20);
            chairGroup.add(armSupport);

            const armCap = new THREE.Mesh(sharedGeos.armCap, materials.CHAIR_BRASS);
            armCap.rotation.x = Math.PI / 2;
            armCap.position.set(ox, 0.58, -0.26);
            chairGroup.add(armCap);
        });

        // 4. Piètement vintage en fonte noire & patin de sol
        const stanchion = new THREE.Mesh(sharedGeos.stanchion, materials.CHAIR_WOOD);
        stanchion.position.set(0, 0.18, 0);
        chairGroup.add(stanchion);

        const foot = new THREE.Mesh(sharedGeos.legFoot, materials.CHAIR_WOOD);
        foot.position.set(0, 0.02, 0);
        chairGroup.add(foot);

        return chairGroup;
    }

    // --- Point de Fosse Debout (Disque de sol stylisé) ---
    function createStandingSpotMesh(statusMaterial) {
        const spotGroup = new THREE.Group();

        // Disque central lumineux au sol
        const discMesh = new THREE.Mesh(sharedGeos.standingDisc, statusMaterial);
        discMesh.position.y = 0.02;
        discMesh.userData.isVelvet = true;
        spotGroup.add(discMesh);

        // Anneau extérieur en laiton
        const rimMesh = new THREE.Mesh(sharedGeos.standingRim, materials.BRASS_TRIM);
        rimMesh.rotation.x = Math.PI / 2;
        rimMesh.position.y = 0.03;
        spotGroup.add(rimMesh);

        // Médaillon central doré
        const badgeMesh = new THREE.Mesh(sharedGeos.standingBadge, materials.CHAIR_BRASS);
        badgeMesh.position.y = 0.04;
        spotGroup.add(badgeMesh);

        return spotGroup;
    }

    async function loadSeats() {
        const loader = document.getElementById('canvas-loader');
        const isExplore = canvas.getAttribute('data-mode') === 'explore' || isNaN(eventId);
        const url = isExplore ? '/api/venue/seats' : `/api/events/${eventId}/seats`;
        try {
            const res = await fetch(url);
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
            // ignore glitch réseau
        }
    }

    function renderAllSeats() {
        // Nettoyage complet
        seatMeshMap.forEach(mesh => scene.remove(mesh));
        seatMeshMap.clear();
        seatPickRoots.length = 0;

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

            const isStanding = (zoneName === 'FRONT_STAGE' && !isSeated);
            const pos = computeSeatPosition(zoneName, row, number, isStanding);
            const statusMat = materials[status] || materials.AVAILABLE;

            const seatObject = isStanding
                ? createStandingSpotMesh(statusMat)
                : createLuxuryArmchairMesh(statusMat);

            seatObject.position.set(pos.x, pos.y, pos.z);
            seatObject.rotation.y = pos.rotY;

            // Données associées pour raycasting
            seatObject.userData = {
                seatId: seat.id,
                row: row,
                number: number,
                zoneName: zoneName,
                priceCents: seat.priceCents,
                status: status,
                holdTtl: seat.holdTtl,
                isSeated: !isStanding,
                baseY: pos.y
            };

            // Tous les mesh enfants partagent ces métadonnées pour détection instantanée
            seatObject.traverse(child => {
                if (child.isMesh) {
                    const isVelvet = child.userData && child.userData.isVelvet ? true : false;
                    child.userData = Object.assign({}, seatObject.userData, { isVelvet: isVelvet });
                }
            });

            scene.add(seatObject);
            seatMeshMap.set(seat.id, seatObject);
            seatPickRoots.push(seatObject);
        });

        updateCartDrawer(holdsForMe);
    }

    function computeSeatPosition(zoneName, row, number, isStanding) {
        let x = 0, y = 0, z = 0, rotY = 0;

        if (zoneName === 'FRONT_STAGE') {
            const colIndex = (number - 1) % 20;
            const rowIndex = Math.floor((number - 1) / 20);

            if (isStanding) {
                // Grille aérée de fosse debout
                x = (colIndex - 9.5) * 1.25;
                z = -14.8 + (rowIndex * 1.45);
                y = 0.02;
                rotY = 0;
            } else {
                // Rangées de parterre basse devant la scène
                x = (colIndex - 9.5) * 1.18;
                z = -14.8 + (rowIndex * 1.35);
                y = 0.05;
                rotY = 0;
            }
        } else if (zoneName === 'ORCHESTRA') {
            // 8 rangées concentriques (160 places) étagées doucement sur le parquet sombre
            let rowIndex = 0;
            if (typeof row === 'string') {
                if (row >= 'A' && row <= 'H') {
                    rowIndex = row.charCodeAt(0) - 'A'.charCodeAt(0);
                } else if (row >= 'E' && row <= 'L') {
                    rowIndex = row.charCodeAt(0) - 'E'.charCodeAt(0);
                } else {
                    rowIndex = Math.min(Math.floor((number - 1) / 20), 7);
                }
            }
            const colIndex = (number - 1) % 20;

            // Fan compact derrière l'avant-scène : les rangées restent lisibles
            // et la dernière rangée ne dérive pas vers la galerie.
            const radius = 11.5 + (rowIndex * 1.35);
            const angleSpan = 1.25;
            const angle = (-angleSpan / 2) + ((colIndex + 0.5) / 20) * angleSpan;

            x = Math.sin(angle) * radius;
            z = -15.5 + (Math.cos(angle) * radius * 0.72);
            y = 0.05 + (rowIndex * 0.12); // Pente d'auditorium sans géométrie occultante
            // Le dossier regarde vers le fond de scène, donc la rotation suit
            // le vecteur réel entre le fauteuil et le centre du plateau.
            rotY = Math.atan2(x, z + 25);
        } else if (zoneName === 'LEFT_BOX') {
            // 3 rangées superposées (L1, L2, L3) flanquant la scène à gauche
            let rowIndex = 0;
            if (typeof row === 'string' && row.startsWith('L')) {
                rowIndex = Math.max(0, parseInt(row.replace('L', ''), 10) - 1);
            } else {
                rowIndex = Math.floor((number - 1) / 10);
            }
            const seatIndex = (number - 1) % 10;

            x = -22.5 + (rowIndex * 1.3);
            z = -14.2 + (seatIndex * 1.45);
            y = 3.6 + (rowIndex * 2.2);
            rotY = Math.atan2(x, z + 25); // Orienté vers le centre du plateau
        } else if (zoneName === 'RIGHT_BOX') {
            // 3 rangées superposées (R1, R2, R3) flanquant la scène à droite
            let rowIndex = 0;
            if (typeof row === 'string' && row.startsWith('R')) {
                rowIndex = Math.max(0, parseInt(row.replace('R', ''), 10) - 1);
            } else {
                rowIndex = Math.floor((number - 1) / 10);
            }
            const seatIndex = (number - 1) % 10;

            x = 22.5 - (rowIndex * 1.3);
            z = -14.2 + (seatIndex * 1.45);
            y = 3.6 + (rowIndex * 2.2);
            rotY = Math.atan2(x, z + 25); // Orienté vers le centre du plateau
        } else if (zoneName === 'BALCONY') {
            // 5 rangées incurvées le long du fer à cheval supérieur
            let rowIndex = 0;
            if (typeof row === 'string' && row.startsWith('B')) {
                rowIndex = Math.max(0, row.charCodeAt(1) - 'A'.charCodeAt(0));
            } else if (typeof row === 'string' && row >= 'M' && row <= 'Q') {
                rowIndex = row.charCodeAt(0) - 'M'.charCodeAt(0);
            } else {
                rowIndex = Math.floor((number - 1) / 20);
            }
            const colIndex = (number - 1) % 20;

            const radius = 32.2 + (rowIndex * 1.35);
            // Les 20 fauteuils occupent une portion resserrée de la galerie :
            // une courbe lisible, sans les grands espaces qui les faisaient flotter.
            const angleSpan = 0.92;
            const angle = (-angleSpan / 2) + ((colIndex + 0.5) / 20) * angleSpan;

            x = Math.sin(angle) * radius;
            z = -11.5 + (Math.cos(angle) * radius * 0.78);
            y = 7.1 + (rowIndex * 0.68); // Chaque rangée repose sur son gradin
            rotY = Math.atan2(x, z + 25);
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

                const statusMat = materials[status] || materials.AVAILABLE;
                chair.traverse(child => {
                    if (child.isMesh && (child.userData.isVelvet || child.geometry === sharedGeos.cushion || child.geometry === sharedGeos.backrest || child.geometry === sharedGeos.armrest || child.geometry === sharedGeos.standingDisc)) {
                        child.material = statusMat;
                    }
                });

                if (status === 'HELD_BY_ME') {
                    holdsForMe.push(seat);
                }
            }
        });
        updateCartDrawer(holdsForMe);
    }

    function onPointerDown(event) {
        pointerDownPos.x = event.clientX;
        pointerDownPos.y = event.clientY;
        dismissHint();
    }

    function dismissHint() {
        if (hintDismissed) return;
        hintDismissed = true;
        const hint = document.getElementById('interaction-hint');
        if (hint) {
            hint.style.opacity = '0';
            hint.style.transform = 'translateY(6px)';
            setTimeout(() => { if (hint.parentNode) hint.parentNode.removeChild(hint); }, 400);
        }
    }

    function onPointerMove(event) {
        dismissHint();
        const rect = canvas.getBoundingClientRect();
        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(seatPickRoots, true);

        const tooltip = document.getElementById('seat-tooltip');
        let foundTarget = null;
        let foundGroup = null;

        for (let i = 0; i < intersects.length; i++) {
            const obj = intersects[i].object;
            if (obj.userData && obj.userData.seatId) {
                foundTarget = obj.userData;
                // Remonter jusqu'au groupe de fauteuil parent
                let p = obj;
                while (p.parent && p.parent !== scene) {
                    p = p.parent;
                }
                foundGroup = p;
                break;
            }
        }

        // Gestion du survol / highlight interactif
        if (foundGroup !== hoveredSeatGroup) {
            // Rétablir le précédent fauteuil survolé
            if (hoveredSeatGroup) {
                hoveredSeatGroup.position.y = originalHoverY;
                const prevStatus = hoveredSeatGroup.userData.status || 'AVAILABLE';
                const matToRestore = materials[prevStatus] || materials.AVAILABLE;
                hoveredSeatGroup.traverse(c => {
                    if (c.isMesh && (c.userData.isVelvet || c.geometry === sharedGeos.cushion || c.geometry === sharedGeos.backrest || c.geometry === sharedGeos.standingDisc)) {
                        c.material = matToRestore;
                    }
                });
            }

            // Activer le survol sur le nouveau fauteuil
            if (foundGroup) {
                originalHoverY = foundGroup.userData.baseY !== undefined ? foundGroup.userData.baseY : foundGroup.position.y;
                foundGroup.position.y = originalHoverY + 0.12; // Légère élévation cinématique

                if (foundTarget.status === 'AVAILABLE') {
                    foundGroup.traverse(c => {
                        if (c.isMesh && (c.userData.isVelvet || c.geometry === sharedGeos.cushion || c.geometry === sharedGeos.backrest || c.geometry === sharedGeos.standingDisc)) {
                            c.material = materials.HOVER_SHEEN;
                        }
                    });
                }
            }
            hoveredSeatGroup = foundGroup;
        }

        const banner = document.getElementById('zone-banner');
        if (foundTarget) {
            canvas.style.cursor = 'pointer';
            if (banner) {
                clearTimeout(zoneBannerTimeout);
                banner.textContent = formatZoneName(foundTarget.zoneName);
                banner.style.opacity = '1';
                banner.style.transform = 'translateX(-50%) translateY(0)';
            }
            if (tooltip) {
                tooltip.style.left = `${event.clientX - rect.left + 16}px`;
                tooltip.style.top = `${event.clientY - rect.top - 20}px`;
                tooltip.classList.remove('hidden');
                tooltip.style.display = 'block';

                const isExplore = canvas.getAttribute('data-mode') === 'explore' || isNaN(eventId);
                document.getElementById('tooltip-seat-name').innerText = `Rang ${foundTarget.row} — N° ${foundTarget.number}`;
                document.getElementById('tooltip-seat-zone').innerText = formatZoneName(foundTarget.zoneName);

                const priceEl = document.getElementById('tooltip-seat-price');
                const statusEl = document.getElementById('tooltip-seat-status');
                if (isExplore) {
                    if (priceEl) priceEl.innerText = 'Visite libre';
                    if (statusEl) {
                        statusEl.innerText = 'Emplacement numéroté';
                        statusEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border bg-amber-500/10 text-amber-300 border-amber-500/20';
                    }
                } else {
                    if (priceEl) priceEl.innerText = `${Math.round(foundTarget.priceCents / 100)} MAD`;
                    if (statusEl) {
                        statusEl.innerText = formatStatusLabel(foundTarget.status);
                        statusEl.className = formatStatusClass(foundTarget.status);
                    }
                }
            }
        } else {
            canvas.style.cursor = 'default';
            if (banner) {
                banner.style.opacity = '0';
                banner.style.transform = 'translateX(-50%) translateY(-6px)';
            }
            if (tooltip) {
                tooltip.classList.add('hidden');
                tooltip.style.display = 'none';
            }
        }
    }

    function formatZoneName(zone) {
        switch (zone) {
            case 'FRONT_STAGE': return 'Avant-Scène';
            case 'ORCHESTRA': return 'Orchestre Prestige';
            case 'LEFT_BOX': return 'Loge Privée Gauche';
            case 'RIGHT_BOX': return 'Loge Privée Droite';
            case 'BALCONY': return 'Balcon & Galerie';
            default: return zone || '';
        }
    }

    function formatStatusLabel(status) {
        switch (status) {
            case 'AVAILABLE': return 'Disponible';
            case 'HELD_BY_ME': return 'Dans votre sélection';
            case 'HELD_BY_OTHER': return 'Réservé (15 min)';
            case 'SOLD': return 'Vendu';
            default: return status;
        }
    }

    function formatStatusClass(status) {
        const base = 'px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ';
        switch (status) {
            case 'AVAILABLE': return base + 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
            case 'HELD_BY_ME': return base + 'bg-amber-500/20 text-amber-300 border-amber-500/30';
            case 'HELD_BY_OTHER': return base + 'bg-slate-700/40 text-slate-300 border-slate-600/30';
            case 'SOLD': return base + 'bg-rose-500/20 text-rose-300 border-rose-500/30';
            default: return base;
        }
    }

    async function onCanvasClick(event) {
        dismissHint();
        if (canvas.getAttribute('data-mode') === 'explore' || isNaN(eventId)) {
            // Mode visite libre : pas d'action de réservation au clic
            return;
        }

        const dx = event.clientX - pointerDownPos.x;
        const dy = event.clientY - pointerDownPos.y;
        if (Math.hypot(dx, dy) > 6) return; // Ignore drag release from camera orbit

        const rect = canvas.getBoundingClientRect();
        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(seatPickRoots, true);

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
            showToast('Ce siège est déjà vendu pour cette représentation.', 'error');
        } else if (currentStatus === 'HELD_BY_OTHER') {
            showToast('Ce siège est actuellement réservé par un autre spectateur (15 min).', 'warning');
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
                showToast('Fauteuil ajouté à votre sélection.', 'success');
                await loadSeats();
                if (typeof updateCartHeader === 'function') updateCartHeader();
            } else if (res.status === 401) {
                showToast('Veuillez vous connecter pour réserver vos places.', 'warning');
                setTimeout(() => { window.location.href = '/login'; }, 1000);
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
                showToast('Fauteuil retiré de votre sélection.', 'info');
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

    // Exposer la libération pour le panneau latéral
    window.releaseSeatFromCart = releaseSeat;

    function updateCartDrawer(holdsForMe) {
        if (canvas.getAttribute('data-mode') === 'explore') return;
        const listEl = document.getElementById('held-seats-list');
        const countBadge = document.getElementById('held-count-badge');
        const totalEl = document.getElementById('held-total-price');
        const ctaBtn = document.getElementById('checkout-cta-button');

        if (!listEl) return;

        countBadge.innerText = `${holdsForMe.length} place${holdsForMe.length > 1 ? 's' : ''}`;

        if (holdsForMe.length === 0) {
            listEl.innerHTML = `<div style="text-align:center;padding:36px 0;color:var(--muted);font-size:var(--t-5);">Cliquez sur un fauteuil libre dans la salle pour l'ajouter à votre sélection.</div>`;
            totalEl.innerText = '0 MAD';
            ctaBtn.style.opacity = '0.4';
            ctaBtn.style.pointerEvents = 'none';
            return;
        }

        ctaBtn.style.opacity = '1';
        ctaBtn.style.pointerEvents = 'auto';

        let totalCents = 0;
        let html = '';

        holdsForMe.forEach(s => {
            totalCents += s.priceCents;
            const priceMad = Math.round(s.priceCents / 100);
            const initTtl = s.holdTtl !== undefined && s.holdTtl !== null ? s.holdTtl : 900;
            const m = Math.floor(initTtl / 60);
            const sec = (initTtl % 60).toString().padStart(2, '0');

            html += `
                <div class="seat-item">
                    <div>
                        <div style="font-weight:500;color:var(--cream);">Rang ${s.row} — N° ${s.number}</div>
                        <div class="meta" style="font-size:0.75rem;">${formatZoneName(s.zone ? s.zone.name : '')} · ${priceMad} MAD</div>
                        <div class="meta" style="font-size:0.7rem;color:var(--muted);font-family:monospace;margin-top:2px;">Réservé encore <span class="seat-countdown" data-ttl="${initTtl}">${m}:${sec}</span></div>
                    </div>
                    <button type="button" onclick="releaseSeatFromCart(${s.id})" class="meta" style="cursor:pointer;border-bottom:1px solid var(--rule);padding-bottom:1px;">
                        Retirer
                    </button>
                </div>
            `;
        });

        listEl.innerHTML = html;
        totalEl.innerText = `${Math.round(totalCents / 100)} MAD`;
    }

    function showToast(message, type = 'info') {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = 'px-4 py-3 rounded shadow-2xl backdrop-blur-xl border text-xs font-medium transform transition-all duration-300 translate-y-2 opacity-0 pointer-events-auto';

        if (type === 'success') {
            toast.className += ' bg-[#171316] border-emerald-500/40 text-emerald-200';
            toast.innerText = message;
        } else if (type === 'error') {
            toast.className += ' bg-[#171316] border-rose-500/40 text-rose-300';
            toast.innerText = message;
        } else if (type === 'warning') {
            toast.className += ' bg-[#171316] border-amber-500/40 text-amber-300';
            toast.innerText = message;
        } else {
            toast.className += ' bg-[#171316] border-white/10 text-slate-200';
            toast.innerText = message;
        }

        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.remove('translate-y-2', 'opacity-0'), 10);
        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // --- Chorégraphie Caméra & Transitions Cinématiques ---
    window.setCameraPreset = function (mode) {
        if (!controls || !camera) return;

        // Mise à jour de l'état actif sur les boutons
        document.querySelectorAll('.view-btn').forEach(btn => {
            if (btn.getAttribute('data-view') === mode) {
                btn.classList.add('active-view');
            } else {
                btn.classList.remove('active-view');
            }
        });

        let targetPos, targetLookAt;

        if (mode === 'scene') {
            // Vue Scène : Perspective de l'artiste depuis le plateau vers les 400 places & le lustre
            targetPos = new THREE.Vector3(0, 4.5, -19);
            targetLookAt = new THREE.Vector3(0, 7, 8);
        } else if (mode === 'top') {
            // Plan 2D : Vue zénithale architecturale
            targetPos = new THREE.Vector3(0, 55, 0.01);
            targetLookAt = new THREE.Vector3(0, 0, 0);
        } else if (mode === 'orchestra' || mode === 'orchestre') {
            // Zoom Orchestre : Cadrage plongeant direct sur les 160 fauteuils
            targetPos = new THREE.Vector3(0, 16.5, 23);
            targetLookAt = new THREE.Vector3(0, 1.2, 4.0);
        } else if (mode === 'front_stage' || mode === 'avant_scene') {
            // Zoom Avant-Scène : Gros plan sur les fauteuils / fosse devant la scène
            targetPos = new THREE.Vector3(0, 8.5, -2.5);
            targetLookAt = new THREE.Vector3(0, 0.5, -12.5);
        } else if (mode === 'balcony' || mode === 'balcon') {
            // Zoom Balcon : Cadrage du fer à cheval supérieur
            targetPos = new THREE.Vector3(0, 19, -4);
            targetLookAt = new THREE.Vector3(0, 8.5, 15);
        } else if (mode === 'left_box' || mode === 'loge_gauche') {
            // Zoom Loges Gauche : Vue en face depuis la droite
            targetPos = new THREE.Vector3(4.0, 9.0, -7.0);
            targetLookAt = new THREE.Vector3(-21.5, 5.5, -7.0);
        } else if (mode === 'right_box' || mode === 'loge_droite') {
            // Zoom Loges Droite : Vue en face depuis la gauche
            targetPos = new THREE.Vector3(-4.0, 9.0, -7.0);
            targetLookAt = new THREE.Vector3(21.5, 5.5, -7.0);
        } else {
            // Vue Achat / Panoramique d'ensemble
            targetPos = new THREE.Vector3(0, 32, 42);
            targetLookAt = new THREE.Vector3(0, 2, -4);
        }

        animateCameraTo(targetPos, targetLookAt, 1100);
    };

    function animateCameraTo(targetPos, targetLookAt, durationMs) {
        cameraTween = {
            startPos: camera.position.clone(),
            startTarget: controls.target.clone(),
            targetPos: targetPos.clone(),
            targetTarget: targetLookAt.clone(),
            startTime: performance.now(),
            duration: durationMs
        };
    }

    function onWindowResize() {
        if (!camera || !renderer || !container) return;
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    }

    function animate() {
        requestAnimationFrame(animate);

        const now = performance.now();

        // 1. Interpolation fluide de la caméra (easeInOutCubic lerp)
        if (cameraTween) {
            const elapsed = now - cameraTween.startTime;
            const progress = Math.min(elapsed / cameraTween.duration, 1);
            const ease = progress < 0.5
                ? 4 * progress * progress * progress
                : 1 - Math.pow(-2 * progress + 2, 3) / 2;

            camera.position.lerpVectors(cameraTween.startPos, cameraTween.targetPos, ease);
            controls.target.lerpVectors(cameraTween.startTarget, cameraTween.targetTarget, ease);
            controls.update();

            if (progress >= 1) {
                cameraTween = null;
            }
        } else if (controls) {
            controls.update();
        }

        // 2. Micro-balancement atmosphérique du Grand Lustre
        if (chandelierGroup) {
            const t = now * 0.001;
            chandelierGroup.rotation.z = Math.sin(t * 0.45) * 0.006;
            chandelierGroup.rotation.x = Math.cos(t * 0.35) * 0.004;
        }

        // 3. Pulsation lumineuse des sièges sélectionnés
        if (materials.HELD_BY_ME) {
            heldByMePulseT = now * 0.003;
            materials.HELD_BY_ME.emissiveIntensity = 0.35 + Math.sin(heldByMePulseT) * 0.25;
        }

        // 4. Render
        if (renderer && scene && camera) {
            renderer.render(scene, camera);
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();