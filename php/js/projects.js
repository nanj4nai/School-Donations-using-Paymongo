document.addEventListener("DOMContentLoaded", () => {
    const projectGrid = document.querySelector(".project-grid");
    const modal = document.getElementById("donationModal");
    const closeModal = document.getElementById("closeModal");
    let projects = [];

    // Donation form elements
    const donationForm = document.getElementById('donationForm');
    const amountInput = document.getElementById('amount');
    const anonymousCheckbox = document.getElementById('anonymous');
    const donorFields = document.getElementById('donorFields');
    const statusMessage = document.getElementById('statusMessage');
    const amountError = document.getElementById('amountError');

    // Confirmation modal elements
    const confirmModal = document.getElementById('confirmModal');
    const modalMessage = document.getElementById('modalMessage');
    const modalCancel = document.getElementById('modalCancel');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalContent = document.getElementById('modalContent');


    let pendingPayload = null; // Store payload temporarily until confirmed

    // Fetch projects from backend
    fetch('php/fetch_projects.php')
        .then(res => res.json())
        .then(data => {
            projects = data;
            projects.forEach(project => {
                const card = document.createElement('div');
                card.classList.add('project-card');
                const imageUrl = project.images.length ? project.images[0] : 'images/placeholder.jpg';

                card.innerHTML = `
                    <img src="${imageUrl}" alt="${project.name}">
                    <div class="project-info">
                        <span class="location">${project.location || ''}</span>
                        <h3>${project.name}</h3>
                        <p>₱${project.raised || 0} raised</p>
                        <div class="progress-bar">
                            <div class="progress" style="width: ${Math.min((project.raised / project.goal_amount) * 100 || 0, 100)}%;"></div>
                        </div>
                        <button class="donateBtn" data-id="${project.id}">
                            <i class="fas fa-hand-holding-heart"></i> Donate Now
                        </button>
                    </div>
                `;
                projectGrid.appendChild(card);
            });

            projectGrid.querySelectorAll(".donateBtn").forEach(btn => {
                btn.addEventListener("click", () => openDonationModal(btn.dataset.id));
            });

            // Staggered reveal
            const cards = document.querySelectorAll(".project-card");
            const cardObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        cards.forEach((card, i) => setTimeout(() => card.classList.add("active"), i * 150));
                        cardObserver.disconnect();
                    }
                });
            }, { threshold: 0.2 });
            if (cards.length) cardObserver.observe(cards[0]);
        })
        .catch(console.error);

    // Open donation modal
    function openDonationModal(projectId) {
        const project = projects.find(p => p.id == projectId);
        if (!project) return;

        const modalImg = document.getElementById("modalProjectImage");
        const modalName = document.getElementById("modalProjectName");
        const modalDesc = document.getElementById("modalProjectDescription");
        const projectInput = document.getElementById("project_id"); // hidden input

        if (modalImg) modalImg.src = project.images.length ? project.images[0] : 'images/placeholder.jpg';
        if (modalImg) modalImg.alt = project.name;
        if (modalName) modalName.textContent = project.name;
        if (modalDesc) modalDesc.textContent = project.description || 'No description available.';

        if (projectInput) projectInput.value = project.id; // set hidden input value

        modal.classList.remove("hidden");
        // Clear previous amount selection
        document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('bg-indigo-500', 'text-white'));
        amountInput.value = '';
    }


    // Close modal
    closeModal.addEventListener("click", () => modal.classList.add("hidden"));
    window.addEventListener("click", e => { if (e.target === modal) modal.classList.add("hidden"); });

    // Reveal other sections
    const reveals = document.querySelectorAll('.reveal');
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });
    reveals.forEach(el => observer.observe(el));

    // Mobile menu toggle
    window.toggleMenu = () => document.getElementById("menu").classList.toggle("show");

    // Slideshow
    let slideIndex = 0;
    const slides = document.querySelectorAll(".slideshow .slide");
    function showSlides() {
        slides.forEach(slide => slide.classList.remove("active"));
        slideIndex = (slideIndex + 1) % slides.length;
        slides[slideIndex].classList.add("active");
    }
    if (slides.length) setInterval(showSlides, 4000);

    // Amount buttons
    document.querySelectorAll('.amount-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('bg-indigo-500', 'text-white'));
            const amount = this.dataset.amount;
            if (amount === 'custom') {
                this.classList.add('bg-indigo-500', 'text-white');
                amountInput.value = '';
                amountInput.focus();
            } else {
                this.classList.add('bg-indigo-500', 'text-white');
                amountInput.value = amount;
            }
        });
    });

    // Anonymous toggle
    anonymousCheckbox.addEventListener('change', function () {
        if (this.checked) {
            donorFields.style.display = 'none';
            donorFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
        } else {
            donorFields.style.display = 'block';
            document.getElementById('donor_name').setAttribute('required', '');
        }
    });

    // Status helper
    function showStatus(type, message) {
        statusMessage.textContent = message;
        statusMessage.className = '';
        if (type === 'loading') statusMessage.classList.add('text-gray-700');
        if (type === 'success') statusMessage.classList.add('text-green-600', 'font-semibold');
        if (type === 'error') statusMessage.classList.add('text-red-600', 'font-semibold');
    }

    // Shake helper
    function shakeInput(input) {
        input.classList.add('shake');
        setTimeout(() => input.classList.remove('shake'), 300);
    }
    const PAYMONGO_MIN = 20; // Minimum donation
    // Form submission
    donationForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const amountValue = parseFloat(amountInput.value || 0);
        const donorName = anonymousCheckbox.checked ? 'Anonymous' : document.getElementById('donor_name').value.trim();

        // Reset errors
        amountInput.classList.remove('border-red-500', 'ring-red-300');
        amountError.classList.add('hidden');

        if (isNaN(amountValue) || amountValue < PAYMONGO_MIN) {
            amountInput.classList.add('border-red-500', 'ring-1', 'ring-red-300');
            amountError.textContent = `The minimum donation is ₱${PAYMONGO_MIN}.`;
            amountError.classList.remove('hidden');
            shakeInput(amountInput);
            amountInput.focus();
            return;
        }

        // Show custom confirmation modal
        modalMessage.textContent = `You are about to donate ₱${amountValue.toLocaleString()} as ${donorName}. Proceed?`;
        confirmModal.classList.remove('hidden');
        modalContent.classList.add('scale-90', 'opacity-0');
        setTimeout(() => modalContent.classList.remove('scale-90', 'opacity-0'), 50);

        // Store payload temporarily
        pendingPayload = {
            project_id: document.getElementById('project_id').value,
            amount: amountValue,
            currency: 'PHP',
            donor_is_anonymous: anonymousCheckbox.checked ? 1 : 0
        };
        if (!anonymousCheckbox.checked) {
            pendingPayload.donor_name = document.getElementById('donor_name').value.trim();
            pendingPayload.donor_email = document.getElementById('donor_email')?.value?.trim() || '';
            pendingPayload.donor_phone = document.getElementById('donor_phone')?.value?.trim() || '';
        }
    });

    // Cancel button
    modalCancel.addEventListener('click', () => confirmModal.classList.add('hidden'));
    window.addEventListener('click', e => { if (e.target === confirmModal) confirmModal.classList.add('hidden'); });


    // Confirm button
    modalConfirm.addEventListener('click', () => {
        if (!pendingPayload) return;
        confirmModal.classList.add('hidden');
        showStatus('loading', 'Creating checkout session...');
        fetch('php/create_checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pendingPayload)
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showStatus('error', data.error || 'Failed to create checkout.');
                    return;
                }
                showStatus('success', 'Redirecting to payment gateway...');
                window.location.href = data.checkout_url;
            })
            .catch(err => {
                showStatus('error', 'Network error. Please try again.');
                console.error(err);
            });
    });
});
