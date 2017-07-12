$(function() {
    var createFormTextField = function(label,name,value) {
        return '<label for="'+name+'" >'+label+'</label><br /><input type="text" name="' + name + '" value="'+value+'" class="form-control" /><br>';
    };
    var createFormForType = function(prefix, type,fielddata) {
        console.log(fielddata);
        var rtn = "";
        switch (type) {
            case "textInput":
                rtn += createFormTextField('Label',prefix + "[label]",fielddata.label);
                rtn += createFormTextField('Key',prefix + "[key]",fielddata.key);
                rtn += createFormTextField('Type',prefix + "[type]",fielddata.type);
                break;
            default:
                return "type \"" + type + "\" unknown!";
        }
        return rtn;
    };

    $('.c-element-edit--field').each(function(i) {
        $(this).html(createFormForType('field[' + i + ']',$(this).data('type'),$(this).data('fielddata')));
    });

    $( "#c-element-edit--fields" ).sortable({
    }).disableSelection();

});