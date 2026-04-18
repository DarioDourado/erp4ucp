$(function(){
    $(document).on('click','.delete-btn',function(e){
        e.preventDefault();

        var link = $(this).attr("href");

        Swal.fire({
            title: 'Tem a certeza?',
            text: "Não vai conseguir reverter esta ação!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sim, avançar!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = link;
            }
        });
    });
});

